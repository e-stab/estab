<?php

declare(strict_types=1);

/**
 * Compatibility facade for category reads in the legacy four-part form.
 *
 * Mutations live exclusively in katgoedt.php and app/category.php. Keeping the
 * historic class name avoids changing message/list constructors while removing
 * all ext/mysql calls, request-derived identifiers and unescaped option output.
 */

require_once __DIR__ . '/../app/category.php';
require_once __DIR__ . '/../4fcfg/config.inc.php';
require_once __DIR__ . '/../4fcfg/dbcfg.inc.php';
require_once __DIR__ . '/../4fcfg/e_cfg.inc.php';

class kategorien
{
    // Deliberately untyped: historical callers and the Listen subclass treat
    // these public compatibility properties as mutable mixed values.
    public $db_tablname = '';
    public $db_tablnamelk = '';
    public $dbtyp = '';
    public $result = null;
    public $resultcount = 0;
    public $db_tbl = '';
    public $stab_fkt = '';

    protected ?mysqli $categoryConnection = null;
    protected array $categoryScope = [];
    protected array $categoryIdentity = [];

    public function __construct(string $table)
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            http_response_code(403);
            throw new EstabCategoryAuthorizationException('Anmeldung erforderlich.');
        }
        estab_auth_require_session($_SESSION);
        $identity = estab_auth_session_identity($_SESSION);
        if ($identity === null) {
            throw new EstabCategoryAuthorizationException('Anmeldung erforderlich.');
        }

        /** @var array<string,string> $conf_4f_tbl */
        global $conf_4f_tbl;
        /** @var array<string,string> $conf_4f_db */
        global $conf_4f_db;

        $this->dbtyp = estab_category_validate_type($table);
        $this->categoryIdentity = $identity;
        $this->categoryScope = estab_category_scope($this->dbtyp, $identity, $conf_4f_tbl);
        $this->db_tablname = $this->categoryScope['category_table'];
        $this->db_tablnamelk = $this->categoryScope['link_table'];
        // The surrounding legacy form still performs later mysql_* calls
        // without an explicit handle. The compatibility connector therefore
        // remains alive until PHP's request cleanup, as it did upstream.
        $this->categoryConnection = estab_auth_connect($conf_4f_db);
    }

    protected function connection(): mysqli
    {
        if (!$this->categoryConnection instanceof mysqli) {
            throw new RuntimeException('Kategorie-Datenbank ist nicht verbunden.');
        }
        return $this->categoryConnection;
    }

    /** Preserve the historic one-based result array. */
    public function lese_kategorien(): void
    {
        $rows = estab_category_fetch_all($this->connection(), $this->categoryScope);
        $this->resultcount = count($rows);
        $this->result = [];
        foreach ($rows as $index => $row) {
            $this->result[$index + 1] = $row;
        }
    }

    public function db_get(mixed $lfd): array|false
    {
        try {
            $categoryId = estab_category_positive_id($lfd, 'Kategorie-ID');
        } catch (EstabCategoryInputException) {
            $this->result = false;
            $this->resultcount = 0;
            return false;
        }
        $row = estab_category_fetch_one($this->connection(), $this->categoryScope, $categoryId);
        $this->result = $row ?? false;
        $this->resultcount = $row === null ? 0 : 1;
        return $row ?? false;
    }

    public function db_get_kategobymsg(mixed $lfd): array|false
    {
        try {
            $messageId = estab_category_positive_id($lfd, 'Meldungs-ID');
        } catch (EstabCategoryInputException) {
            $this->result = false;
            $this->resultcount = 0;
            return false;
        }
        $row = estab_category_fetch_for_message(
            $this->connection(),
            $this->categoryScope,
            $messageId
        );
        $this->result = $row ?? false;
        $this->resultcount = $row === null ? 0 : 1;
        return $row ?? false;
    }

    /**
     * Return the legacy projections without building SQL fragments dynamically.
     */
    public function get_data(mixed $no): array
    {
        $projection = is_int($no) || is_string($no) ? (int) $no : 3;
        $rows = estab_category_fetch_all($this->connection(), $this->categoryScope);
        $projected = [];
        foreach ($rows as $row) {
            $projected[] = match ($projection) {
                1 => ['kategorie' => $row['kategorie']],
                2 => ['beschreibung' => $row['beschreibung']],
                4 => ['lfd' => $row['lfd'], 'kategorie' => $row['kategorie']],
                5 => ['lfd' => $row['lfd'], 'beschreibung' => $row['beschreibung']],
                default => $row,
            };
        }
        $this->result = $projected;
        $this->resultcount = count($projected);
        return $projected;
    }

    /** Render a safe category select whose values are immutable numeric IDs. */
    public function pulldown_kategorien(mixed $kategoNo, mixed $mitLeer, mixed $ordnum): void
    {
        $selectedId = null;
        if ($kategoNo !== '' && $kategoNo !== null) {
            try {
                $selectedId = estab_category_positive_id($kategoNo, 'Kategorie-ID');
            } catch (EstabCategoryInputException) {
                $selectedId = null;
            }
        }
        $position = is_string($ordnum) && in_array($ordnum, ['oben', 'unten'], true)
            ? $ordnum
            : 'oben';
        $rows = estab_category_fetch_all($this->connection(), $this->categoryScope);
        $name = 'category_' . $this->dbtyp . '_' . $position;

        echo '<select name="' . estab_auth_html($name) . '">' . "\n";
        if ((bool) $mitLeer) {
            echo '<option value=""' . ($selectedId === null ? ' selected' : '') . '></option>' . "\n";
        }
        foreach ($rows as $row) {
            $rowId = (int) $row['lfd'];
            echo '<option value="' . estab_auth_html($rowId) . '"'
                . ($selectedId === $rowId ? ' selected' : '') . '>'
                . estab_auth_html($row['kategorie'])
                . '</option>' . "\n";
        }
        echo "</select>\n";
    }

    /** Safe read-only rendering retained for legacy callers. */
    public function zeige_kategorien(mixed $lfd): void
    {
        $row = $this->db_get($lfd);
        if ($row !== false) {
            echo estab_auth_html($row['kategorie']);
        }
    }
}
