# Hinweise zu Komponenten Dritter

Dieses Dokument erfasst die Komponenten Dritter, die eStab unmittelbar im
Anwendungscontainer und im Pull-only-Registry-Releasepaket ausliefert. Die
Projektlizenz steht separat in `LICENSE`. Reine Entwicklungs- und Testwerkzeuge
aus den Lockdateien werden nicht in diese Laufzeitartefakte kopiert.

Die Container bauen außerdem auf den in den Dockerfiles digest-fixierten
offiziellen PHP- und MariaDB-Images auf. Deren Betriebssystem-, Laufzeit- und
Paketkomponenten behalten ihre jeweiligen Copyright- und Lizenzhinweise. Die
exakte Zusammenstellung jedes veröffentlichten Images wird durch die zum
Release gehörenden SPDX-SBOMs dokumentiert.

## FPDF 1.6

- Autor: Olivier Plathey
- Upstream: <https://www.fpdf.org/>
- Lizenz: permissive FPDF license (`LicenseRef-FPDF`)
- Ausgeliefert in: `4fbak/fpdf.php` und `4fbak/fpdf/font/*.php`
- Verwendung: Erzeugung der PDF-Nachrichtenvordrucke und Einsatzexporte

Der eingebundene Quelltext wurde für die eStab-Laufzeit angepasst. Der
folgende Lizenztext stammt aus der mit dem Projekt überlieferten
FPDF-Distribution:

> Permission is hereby granted, free of charge, to any person obtaining a copy
> of this software to use, copy, modify, distribute, sublicense, and/or sell
> copies of the software, and to permit persons to whom the software is
> furnished to do so.
>
> THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
> IMPLIED.

## Easy PHP Upload 2.29

- Copyright (c) 2004-2006, Olaf Lederer
- Upstream: <http://www.finalwebsites.com/>
- Lizenz: BSD-3-Clause
- Ausgeliefert in: `4fach/upload_class.php`
- Verwendung: validierte Dateiuploads; der eingebundene Quelltext wurde für
  die eStab-Laufzeit angepasst

BSD 3-Clause License:

Copyright (c) 2004-2006, Olaf Lederer
All rights reserved.

Redistribution and use in source and binary forms, with or without
modification, are permitted provided that the following conditions are met:

1. Redistributions of source code must retain the above copyright notice,
   this list of conditions and the following disclaimer.

2. Redistributions in binary form must reproduce the above copyright notice,
   this list of conditions and the following disclaimer in the documentation
   and/or other materials provided with the distribution.

3. Neither the name of finalwebsites.com nor the names of its contributors may
   be used to endorse or promote products derived from this software without
   specific prior written permission.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR
ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
(INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON
ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
(INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.

## Noto Serif Bold Italic

- Copyright 2018 The Noto Project Authors
- Upstream: <https://github.com/notofonts/noto-fonts>
- Upstream-Commit: `ffebf8c1ee449e544955a7e813c54f9b73848eac`
- Datei: `4fbak/fonts/NotoSerif-BoldItalic.ttf`
- SHA-256: `4fb8737145b4a503d548af4b517afdfc532e44a96ac15378257e825741334eec`
- Lizenz: SIL Open Font License 1.1 (`OFL-1.1`)

Der unveränderte Lizenztext liegt zusätzlich unter
`third_party/Noto-OFL-1.1.txt` und im Anwendungscontainer unter
`/usr/share/licenses/estab/Noto-OFL-1.1.txt`.

SIL OPEN FONT LICENSE Version 1.1 - 26 February 2007

PREAMBLE

The goals of the Open Font License (OFL) are to stimulate worldwide
development of collaborative font projects, to support the font creation
efforts of academic and linguistic communities, and to provide a free and open
framework in which fonts may be shared and improved in partnership with
others.

The OFL allows the licensed fonts to be used, studied, modified and
redistributed freely as long as they are not sold by themselves. The fonts,
including any derivative works, can be bundled, embedded, redistributed and/or
sold with any software provided that any reserved names are not used by
derivative works. The fonts and derivatives, however, cannot be released under
any other type of license. The requirement for fonts to remain under this
license does not apply to any document created using the fonts or their
derivatives.

DEFINITIONS

"Font Software" refers to the set of files released by the Copyright Holder(s)
under this license and clearly marked as such. This may include source files,
build scripts and documentation.

"Reserved Font Name" refers to any names specified as such after the copyright
statement(s).

"Original Version" refers to the collection of Font Software components as
distributed by the Copyright Holder(s).

"Modified Version" refers to any derivative made by adding to, deleting, or
substituting -- in part or in whole -- any of the components of the Original
Version, by changing formats or by porting the Font Software to a new
environment.

"Author" refers to any designer, engineer, programmer, technical writer or
other person who contributed to the Font Software.

PERMISSION & CONDITIONS

Permission is hereby granted, free of charge, to any person obtaining a copy of
the Font Software, to use, study, copy, merge, embed, modify, redistribute, and
sell modified and unmodified copies of the Font Software, subject to the
following conditions:

1) Neither the Font Software nor any of its individual components, in Original
or Modified Versions, may be sold by itself.

2) Original or Modified Versions of the Font Software may be bundled,
redistributed and/or sold with any software, provided that each copy contains
the above copyright notice and this license. These can be included either as
stand-alone text files, human-readable headers or in the appropriate
machine-readable metadata fields within text or binary files as long as those
fields can be easily viewed by the user.

3) No Modified Version of the Font Software may use the Reserved Font Name(s)
unless explicit written permission is granted by the corresponding Copyright
Holder. This restriction only applies to the primary font name as presented to
the users.

4) The name(s) of the Copyright Holder(s) or the Author(s) of the Font Software
shall not be used to promote, endorse or advertise any Modified Version, except
to acknowledge the contribution(s) of the Copyright Holder(s) and the
Author(s) or with their explicit written permission.

5) The Font Software, modified or unmodified, in part or in whole, must be
distributed entirely under this license, and must not be distributed under any
other license. The requirement for fonts to remain under this license does not
apply to any document created using the Font Software.

TERMINATION

This license becomes null and void if any of the above conditions are not met.

DISCLAIMER

THE FONT SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO ANY WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT OF COPYRIGHT, PATENT,
TRADEMARK, OR OTHER RIGHT. IN NO EVENT SHALL THE COPYRIGHT HOLDER BE LIABLE FOR
ANY CLAIM, DAMAGES OR OTHER LIABILITY, INCLUDING ANY GENERAL, SPECIAL,
INDIRECT, INCIDENTAL, OR CONSEQUENTIAL DAMAGES, WHETHER IN AN ACTION OF
CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF THE USE OR INABILITY TO USE
THE FONT SOFTWARE OR FROM OTHER DEALINGS IN THE FONT SOFTWARE.
