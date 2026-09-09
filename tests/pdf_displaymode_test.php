<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace mod_videoplayer;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * PDF display-mode stability tests.
 *
 * @package    mod_videoplayer
 * @category   test
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @coversNothing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversNothing]
final class pdf_displaymode_test extends \advanced_testcase {
    /**
     * Legacy and unsupported modes must resolve to the stable PDF.js viewer.
     *
     * @param string|null $requestedmode Requested mode.
     * @coversNothing
     * @dataProvider legacy_mode_provider
     */
    #[DataProvider('legacy_mode_provider')]
    public function test_legacy_modes_resolve_to_pdfjs(?string $requestedmode): void {
        require_once(__DIR__ . '/../locallib.php');

        $this->assertSame('pdfjs', videoplayer_get_safe_pdf_displaymode($requestedmode));
    }

    /**
     * Supply current, legacy and malformed display-mode values.
     *
     * @return array<string, array{0: string|null}>
     */
    public static function legacy_mode_provider(): array {
        return [
            'current PDF.js mode' => ['pdfjs'],
            'legacy standard mode' => ['standard'],
            'PageFlip ebook mode' => ['ebook'],
            'legacy book mode' => ['book'],
            'empty mode' => [''],
            'missing mode' => [null],
            'invalid mode' => ['../../pageflip'],
        ];
    }

    /**
     * The learner entry point must not load alternative PDF renderers.
     *
     * @coversNothing
     */
    public function test_view_uses_only_pdfjs_renderer(): void {
        $viewsource = file_get_contents(__DIR__ . '/../view.php');

        $this->assertIsString($viewsource);
        $this->assertStringContainsString('mod_videoplayer/pdfviewer', $viewsource);
        $this->assertStringNotContainsString('mod_videoplayer/ebookviewer', $viewsource);
        $this->assertStringNotContainsString('mod_videoplayer/bookviewer', $viewsource);
        $this->assertStringNotContainsString('thirdpartylibs/pageflip', $viewsource);
    }

    /**
     * Legacy backups must be normalised during restore.
     *
     * @coversNothing
     */
    public function test_restore_normalises_displaymode_to_pdfjs(): void {
        $restoresource = file_get_contents(
            __DIR__ . '/../backup/moodle2/restore_videoplayer_stepslib.php'
        );

        $this->assertIsString($restoresource);
        $this->assertStringContainsString("\$data->displaymode = 'pdfjs';", $restoresource);
        $this->assertStringNotContainsString("\$data->displaymode = 'ebook';", $restoresource);
    }

    /**
     * Obsolete renderer source and third-party assets must not ship.
     *
     * @coversNothing
     */
    public function test_obsolete_pdf_renderers_are_not_packaged(): void {
        $obsoletepaths = [
            'amd/src/ebookviewer.js',
            'amd/src/bookviewer.js',
            'templates/book.mustache',
            'styles_pageflip_fix.css',
            'thirdpartylibs/pageflip/page-flip.browser.js',
        ];

        foreach ($obsoletepaths as $path) {
            $this->assertFileDoesNotExist(__DIR__ . '/../' . $path);
        }
    }
}
