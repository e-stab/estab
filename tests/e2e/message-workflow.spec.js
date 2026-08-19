const fs = require('node:fs');
const { test, expect } = require('@playwright/test');

function requiredEnvironment(name) {
  const value = process.env[name];
  if (!value) {
    throw new Error(`${name} is required for the Playwright workflow`);
  }
  return value;
}

function readSecret(environmentName) {
  const filename = requiredEnvironment(environmentName);
  return fs.readFileSync(filename, 'utf8').replace(/[\r\n]+$/u, '');
}

const marker = requiredEnvironment('ESTAB_E2E_MARKER');
const password = readSecret('ESTAB_E2E_USER_PASSWORD_FILE');
const adminPassword = readSecret('ESTAB_E2E_ADMIN_PASSWORD_FILE');
const adminUser = process.env.ESTAB_E2E_ADMIN_USER || 'estab-admin';
const shiftName = `Playwright-Schicht ${marker.slice(-8)}`;

const accounts = {
  aw: { name: 'E2E Fernmelder', code: 'pwaw01', function: 'A/W' },
  ldf: { name: 'E2E LdF', code: 'pwldf1', function: 'LdF' },
  si: { name: 'E2E Sichter', code: 'pwsi01', function: 'Si' },
  s1: { name: 'E2E Sachgebiet 1', code: 'pws101', function: 'S1' },
  s2: { name: 'E2E Sachgebiet 2 ETB', code: 'pws201', function: 'S2' },
  s3: { name: 'E2E Sachgebiet 3', code: 'pws301', function: 'S3' },
  s6: { name: 'E2E Sachgebiet 6', code: 'pws601', function: 'S6' },
};

function assignmentForm(page) {
  return page.locator('form').filter({
    has: page.locator(
      'input[name="admin_action"][value="assign_duty_function"]'
    ),
  });
}

async function registerAccount(browser, account) {
  const context = await browser.newContext();
  const page = await context.newPage();
  await page.goto('/4fach/mainindex.php');
  await page.getByRole('button', { name: 'Neues Konto anlegen' }).click();
  await expect(
    page.getByRole('heading', { name: 'Neues Funktionskonto anlegen' })
  ).toBeVisible();
  await page.getByLabel('Name, Vorname').fill(account.name);
  await page.getByLabel('Kürzel').fill(account.code);
  await page
    .getByLabel('Funktion', { exact: true })
    .selectOption(account.function);
  await page.getByLabel('Kennwort', { exact: true }).fill(password);
  await page.getByLabel('Kennwort wiederholen').fill(password);
  await page
    .getByRole('button', { name: 'Konto erstellen und anmelden' })
    .click();
  await page.waitForURL(/\/4fach\/(?:fuehrungsstelle|index)\.php/u);
  await page.goto('/4fach/fuehrungsstelle.php');
  await expect(page.locator('[data-estab-user-code]')).toHaveAttribute(
    'data-estab-user-code',
    account.code
  );
  await expect(page.locator('[data-estab-duty-selection-required]')).toBeVisible();
  return { context, page, account };
}

async function workspace(page) {
  await page.goto('/4fach/index.php');
  await expect(page.locator('[data-estab-message-workspace]')).toBeVisible();
  const navigation = page.frameLocator('iframe[name="vorgaben"]');
  const content = page.frameLocator('iframe[name="mainframe"]');
  await expect(navigation.locator('[data-estab-workflow-menu]')).toBeVisible();
  return { navigation, content };
}

async function openAction(page, actionName, expectedTask) {
  const frames = await workspace(page);
  await frames.navigation
    .getByRole('button', { name: actionName, exact: false })
    .click();
  if (expectedTask) {
    await expect(
      frames.content.locator(`input[name="task"][value="${expectedTask}"]`)
    ).toHaveCount(1);
  }
  return frames.content;
}

async function openQueuedMessage(page, actionName, contentMarker, expectedTask) {
  const content = await openAction(page, actionName, null);
  await expect(content.locator('body')).toContainText(contentMarker);
  await content
    .getByRole('button', { name: contentMarker, exact: true })
    .click();
  await expect(
    content.locator(`input[name="task"][value="${expectedTask}"]`)
  ).toHaveCount(1);
  return content;
}

async function submitLegacyMessageForm(content) {
  const submit = content
    .locator(
      'button[name="absenden_x"], input[type="image"][alt="absenden"]'
    )
    .first();
  await expect(submit).toBeVisible();
  await submit.click();
  await expect(content.locator('input[name="task"]')).toHaveCount(0);
}

async function fillCommonMessageFields(content, values) {
  const address = content.locator('[name="10_anschrift"]').first();
  if (await address.isEditable()) {
    await address.fill(values.address);
  }
  await content.locator('[name="12_betreff"]').first().fill(values.subject);
  await content
    .locator('textarea[name="12_inhalt"]')
    .first()
    .fill(values.content);
  const messageType = content
    .locator('[name="07_durchspruch"][value="D"]')
    .first();
  if (await messageType.isEnabled()) {
    await messageType.check();
  }
  const priority = content.locator('select[name="09_vorrangstufe"]').first();
  if (await priority.count()) {
    await priority.selectOption('eee');
  }
  // Feld 16 gehoert dem Verfasser: die Anwendung setzt die Abfassungszeit
  // nicht mehr selbst ein, weil sie den Zeitpunkt der Erfassung kennt und
  // nicht den der Abfassung.
  const compositionTime = content.locator('[name="12_abfzeit"]').first();
  if (await compositionTime.count() && await compositionTime.isEditable()) {
    await compositionTime.fill(values.compositionTime ?? '1215');
  }
}

async function createOutgoing(page, account) {
  const contentMarker = `${marker}-OUT-${account.function}`;
  const content = await openAction(
    page,
    `Schreiben als ${account.function}`,
    'Stab_schreiben'
  );
  await fillCommonMessageFields(content, {
    address: `E2E Ziel ${account.function}`,
    subject: `E2E Ausgang ${account.function} ${marker}`,
    content: contentMarker,
  });
  await submitLegacyMessageForm(content);
  await expect(content.locator('body')).toContainText(contentMarker);
  return contentMarker;
}

test.describe('vollständiger Nachrichtenablauf', () => {
  test.describe.configure({ mode: 'serial' });

  let adminContext;
  let adminPage;
  const sessions = {};
  let incomingId;
  const outgoingMarkers = [];

  test.beforeAll(async ({ browser }) => {
    adminContext = await browser.newContext({
      httpCredentials: { username: adminUser, password: adminPassword },
    });
    adminPage = await adminContext.newPage();
  });

  test.afterAll(async () => {
    await Promise.all(
      Object.values(sessions).map(({ context }) => context.close())
    );
    await adminContext.close();
  });

  test('registriert persönliche Konten und aktiviert ihre strenge Schicht', async ({
    browser,
  }) => {
    await adminPage.goto('/4fadm/self_registration.php');
    const enableForm = adminPage.locator('form').filter({
      has: adminPage.getByRole('button', { name: 'Jetzt befristet aktivieren' }),
    });
    await enableForm.locator('[name="duration_minutes"]').selectOption('15');
    await enableForm.locator('[name="confirm_activation"]').check();
    await enableForm
      .getByRole('button', { name: 'Jetzt befristet aktivieren' })
      .click();
    await expect(adminPage.getByText('Befristet aktiviert')).toBeVisible();

    for (const [key, account] of Object.entries(accounts)) {
      sessions[key] = await registerAccount(browser, account);
    }

    await adminPage.goto('/4fadm/self_registration.php');
    await adminPage
      .getByRole('button', { name: 'Jetzt deaktivieren' })
      .click();
    await expect(adminPage.getByText('Deaktiviert', { exact: true })).toBeVisible();
    const anonymousContext = await browser.newContext();
    const anonymousPage = await anonymousContext.newPage();
    await anonymousPage.goto('/4fach/mainindex.php');
    await expect(
      anonymousPage.getByRole('button', { name: 'Neues Konto anlegen' })
    ).toBeDisabled();
    await anonymousContext.close();

    await adminPage.goto('/4fadm/fuehrungsstelle.php');
    const createShift = adminPage.locator('form').filter({
      has: adminPage.locator(
        'input[name="admin_action"][value="create_duty_shift"]'
      ),
    });
    await createShift.locator('[name="bezeichnung"]').fill(shiftName);
    await createShift
      .getByRole('button', { name: 'Geplante Schicht anlegen' })
      .click();
    await expect(adminPage.getByText('Die geplante Dienstschicht wurde angelegt.'))
      .toBeVisible();

    for (const account of Object.values(accounts)) {
      const form = assignmentForm(adminPage);
      await form.locator('[name="benutzer_kuerzel"]').selectOption(account.code);
      await form.locator('[name="funktion"]').selectOption(account.function);
      await form.getByRole('button', { name: 'Funktion zuweisen' }).click();
      await expect(
        adminPage.getByText('Die Funktionsbesetzung wurde verbindlich zugewiesen.')
      ).toBeVisible();
    }

    for (const { page, account } of Object.values(sessions)) {
      await page.goto('/4fach/fuehrungsstelle.php');
      await page
        .getByRole('button', { name: 'Verbindlich annehmen' })
        .click();
      await expect(page.getByText('ANGENOMMEN', { exact: true })).toBeVisible();
    }

    await adminPage.goto('/4fadm/fuehrungsstelle.php');
    await adminPage
      .getByRole('button', { name: 'Als erste Schicht aktivieren' })
      .click();
    await expect(adminPage.getByText('Die erste Dienstschicht ist jetzt aktiv.'))
      .toBeVisible();

    for (const { page, account } of Object.values(sessions)) {
      await page.goto('/4fach/fuehrungsstelle.php');
      await page
        .getByRole('button', { name: 'Als Arbeitsfunktion wählen' })
        .click();
      await expect(page.locator('[data-estab-selected-duty-hat]')).toBeVisible();
      await expect(page.locator('[data-estab-user-function]')).toHaveAttribute(
        'data-estab-user-function',
        account.function
      );
    }
  });

  test('veröffentlicht als S6 einen gültigen Fernmeldeweg', async () => {
    const page = sessions.s6.page;
    await page.goto('/4fach/fuehrungsstelle.php');
    const createPlan = page.locator('form').filter({
      has: page.locator('input[name="operation_action"][value="create_plan"]'),
    });
    await createPlan.locator('[name="herkunft"]').fill(`Playwright ${marker}`);
    await createPlan.locator('[name="betriebsleitung"]').fill('S6 E2E');
    await createPlan.locator('[name="bemerkungen"]').fill(
      'Fernmeldeweg für den automatisierten Nachrichtenablauf'
    );
    await createPlan
      .getByRole('button', { name: 'Ersten Entwurf anlegen' })
      .click();
    await expect(page.getByText('Ein Entwurf ist vorhanden.')).toBeVisible();

    await page.getByText('Weiteren Fernmeldeweg hinzufügen').click();
    const addRoute = page.locator('form').filter({
      has: page.locator(
        'input[name="operation_action"][value="add_plan_entry"]'
      ),
    });
    await addRoute.locator('[name="betriebsstelle"]').fill('E2E Funkstelle');
    await addRoute.locator('[name="rufname"]').fill('E2E Gegenstelle');
    await addRoute.locator('[name="medium"]').selectOption('Fu');
    await addRoute.locator('[name="kanal"]').fill('E2E-404');
    await addRoute.locator('[name="bandlage"]').fill('G/U');
    await addRoute.locator('[name="verkehrsform"]').fill('Gegenverkehr');
    await addRoute
      .getByRole('button', { name: 'Weg zum Entwurf hinzufügen' })
      .click();
    await expect(page.getByText('E2E Funkstelle', { exact: true })).toBeVisible();

    await page
      .getByRole('button', { name: /Als Version 1 aktiv schalten/u })
      .click();
    await expect(page.getByRole('heading', { name: 'Aktiver Fernmeldeplan' }))
      .toBeVisible();
    await expect(page.getByText('E2E Gegenstelle', { exact: true })).toBeVisible();
  });

  test('führt einen Eingang über Fernmelder, LdF und Sichter an S1/S2/S3 und ins ETB', async () => {
    const incomingMarker = `${marker}-IN`;
    let content = await openAction(
      sessions.aw.page,
      'Eingang',
      'FM-Eingang'
    );
    await content.locator('[name="01_medium"][value="Fu"]').first().check();
    await content
      .locator('[name="05_gegenstelle"]')
      .first()
      .fill('E2E Gegenstelle');
    await fillCommonMessageFields(content, {
      address: 'E2E-Führungsstelle',
      subject: `E2E Eingang ${marker}`,
      content: incomingMarker,
    });
    await content.locator('[name="11_rufnummer"]').first().fill('+49 711 404');
    await submitLegacyMessageForm(content);

    content = await openQueuedMessage(
      sessions.ldf.page,
      'Disposition',
      incomingMarker,
      'LdF-Eingang'
    );
    incomingId = await content.locator('input[name="00_lfd"]').first().inputValue();
    expect(incomingId).toMatch(/^[1-9][0-9]*$/u);
    await content.locator('[name="13_abseinheit"]').first().fill('E2E Absender');
    await content.locator('[name="incoming_transport_confirmed"]').check();
    await submitLegacyMessageForm(content);

    content = await openQueuedMessage(
      sessions.si.page,
      'Sichten',
      incomingMarker,
      'Stab_sichten'
    );
    await content.locator('[name="16_21"]').check();
    await content.locator('[name="16_41"]').check();
    await content.locator('textarea[name="17_vermerke"]').fill(
      'An S1, S2 und S3 verteilt; ETB-Nachweis folgt.'
    );
    await content
      .getByRole('button', { name: 'Sichtung abschließen' })
      .first()
      .click();
    await expect(content.locator('input[name="task"]')).toHaveCount(0);

    for (const key of ['s1', 's2', 's3']) {
      const recipient = accounts[key].function;
      const recipientContent = await openAction(
        sessions[key].page,
        `Lesen als ${recipient}`,
        null
      );
      await expect(recipientContent.locator('body')).toContainText(incomingMarker);
    }

    const etbPage = sessions.s2.page;
    await etbPage.goto('/stabetb/etb.php');
    await etbPage
      .getByRole('button', { name: 'Neuen ETB-Eintrag anlegen' })
      .click();
    await etbPage.locator('[name="event"]').fill(
      `Eingang ${incomingMarker} an S1, S2 und S3 verteilt.`
    );
    await etbPage.locator('[name="comment"]').fill(
      'Automatisierter E2E-Nachweis der S2-ETB-Führung.'
    );
    await etbPage.getByText('Bezüge und Nachweise (optional)').click();
    await etbPage.locator('[name="message_id"]').fill(incomingId);
    await etbPage
      .getByRole('button', { name: 'ETB-Eintrag speichern' })
      .click();
    await expect(etbPage.getByText(incomingMarker, { exact: false })).toBeVisible();
  });

  test('befördert je einen Ausgang von S1, S2 und S3 über Si, LdF und Fernmelder', async () => {
    for (const key of ['s1', 's2', 's3']) {
      outgoingMarkers.push(
        await createOutgoing(sessions[key].page, accounts[key])
      );
    }

    for (const outgoingMarker of outgoingMarkers) {
      const content = await openQueuedMessage(
        sessions.si.page,
        'Sichten',
        outgoingMarker,
        'Stab_sichten'
      );
      await content.locator('textarea[name="17_vermerke"]').fill(
        'Formal vollständig – an LdF.'
      );
      await content
        .getByRole('button', { name: 'Formal geprüft – an FmZt' })
        .first()
        .click();
      await expect(content.locator('input[name="task"]')).toHaveCount(0);
    }

    for (const outgoingMarker of outgoingMarkers) {
      const content = await openQueuedMessage(
        sessions.ldf.page,
        'Disposition',
        outgoingMarker,
        'LdF-Ausgang'
      );
      await content
        .locator('[name="05_gegenstelle"]')
        .first()
        .fill('E2E Gegenstelle');
      await content.locator('[name="fernmeldeplan_eintrag_id"]').selectOption({
        index: 1,
      });
      await submitLegacyMessageForm(content);
    }

    for (const outgoingMarker of outgoingMarkers) {
      const content = await openQueuedMessage(
        sessions.aw.page,
        'Ausgang',
        outgoingMarker,
        'FM-Ausgang'
      );
      await expect(content.locator('body')).toContainText('E2E-404');
      await content.locator('[name="transportweg_bestaetigt"]').check();
      await submitLegacyMessageForm(content);
    }

    for (const key of ['s1', 's2', 's3']) {
      const authorContent = await openAction(
        sessions[key].page,
        `Lesen als ${accounts[key].function}`,
        null
      );
      await expect(authorContent.locator('body')).toContainText(
        `${marker}-OUT-${accounts[key].function}`
      );
    }

    await sessions.aw.page.goto('/fmtbb/tbb.php');
    await expect(sessions.aw.page.locator('body')).toContainText(
      `E2E Ausgang S1 ${marker}`
    );
    await expect(sessions.aw.page.locator('body')).toContainText(
      `E2E Ausgang S2 ${marker}`
    );
    await expect(sessions.aw.page.locator('body')).toContainText(
      `E2E Ausgang S3 ${marker}`
    );
  });
});
