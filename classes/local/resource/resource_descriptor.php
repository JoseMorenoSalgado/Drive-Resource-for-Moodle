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

namespace mod_videoplayer\local\resource;

use mod_videoplayer\local\provider\bunny_stream;
use mod_videoplayer\local\resource_compatibility;

/**
 * Browser-safe descriptor for one Elearning Stream activity.
 *
 * The active runtime intentionally supports only managed video and Moodle-local
 * protected PDF. Historical remote records are recognised only so they can fail
 * closed and be migrated without reintroducing retired provider logic.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class resource_descriptor {
    /** @var \stdClass Activity instance. */
    private \stdClass $instance;

    /** @var \context_module Module context. */
    private \context_module $context;

    /** @var string Canonical persisted source. */
    private string $source;

    /** @var \stored_file|null Moodle-local PDF file. */
    private ?\stored_file $localfile;

    /**
     * @param \stdClass $instance Activity instance.
     * @param \context_module $context Module context.
     * @param string $source Canonical source.
     * @param \stored_file|null $localfile Moodle-local PDF file.
     */
    private function __construct(
        \stdClass $instance,
        \context_module $context,
        string $source,
        ?\stored_file $localfile = null
    ) {
        $this->instance = $instance;
        $this->context = $context;
        $this->source = $source;
        $this->localfile = $localfile;
    }

    /**
     * Build a descriptor from persisted activity data.
     *
     * @param \stdClass $instance Activity instance.
     * @param \context_module $context Module context.
     * @return self
     */
    public static function from_instance(\stdClass $instance, \context_module $context): self {
        $source = clean_param((string)($instance->source ?? bunny_stream::SOURCE), PARAM_ALPHANUMEXT);

        if ($source === resource_compatibility::SOURCE_LOCALPDF) {
            $file = videoplayer_get_localpdf_file($context);
            return new self($instance, $context, $source, $file ?: null);
        }

        if ($source === bunny_stream::SOURCE) {
            return new self($instance, $context, $source);
        }

        // Historical remote-provider records intentionally fail closed.
        return new self($instance, $context, $source);
    }

    /**
     * Return the persisted source.
     *
     * @return string
     */
    public function source(): string {
        return $this->source;
    }

    /**
     * Whether this activity is a managed Elearning Stream video.
     *
     * @return bool
     */
    public function is_managed_video(): bool {
        return $this->source === bunny_stream::SOURCE;
    }

    /**
     * Backward-compatible alias for internal callers during the provider-neutral refactor.
     *
     * @return bool
     */
    public function is_bunny_stream(): bool {
        return $this->is_managed_video();
    }

    /**
     * Whether this activity is a Moodle-local protected PDF.
     *
     * @return bool
     */
    public function is_pdf(): bool {
        return $this->source === resource_compatibility::SOURCE_LOCALPDF;
    }

    /**
     * Whether this activity is a video.
     *
     * @return bool
     */
    public function is_video(): bool {
        return $this->is_managed_video();
    }

    /**
     * Whether this resource uses the PDF.js reader.
     *
     * @return bool
     */
    public function is_pdf_like(): bool {
        return $this->is_pdf();
    }

    /**
     * Managed provider asset identifier.
     *
     * Provider identifiers remain server-side and are never exported to learner templates.
     *
     * @return string|null
     */
    public function provider_asset_id(): ?string {
        if (!$this->is_managed_video()) {
            return null;
        }

        $assetid = trim((string)($this->instance->providerassetid ?? ''));
        return bunny_stream::is_valid_asset_id($assetid) ? $assetid : null;
    }

    /**
     * Managed provider processing status.
     *
     * @return string
     */
    public function provider_status(): string {
        if (!$this->is_managed_video()) {
            return '';
        }

        return bunny_stream::normalise_status((string)($this->instance->providerstatus ?? ''));
    }

    /**
     * Resource type exposed to the presentation layer.
     *
     * @return string
     */
    public function type(): string {
        if ($this->is_managed_video()) {
            return 'video';
        }
        if ($this->is_pdf()) {
            return 'pdf';
        }

        return 'unsupported';
    }

    /**
     * Moodle-local PDF file.
     *
     * @return \stored_file|null
     */
    public function local_file(): ?\stored_file {
        return $this->localfile;
    }

    /**
     * Whether the descriptor can be rendered.
     *
     * @return bool
     */
    public function is_available(): bool {
        if ($this->is_pdf()) {
            return $this->localfile !== null;
        }

        return $this->is_managed_video() && $this->provider_asset_id() !== null;
    }

    /**
     * Moodle-only protected URL.
     *
     * @param int $cmid Course module id.
     * @param string|null $streammode Optional managed-video mode.
     * @return \moodle_url
     */
    public function protected_url(int $cmid, ?string $streammode = null): \moodle_url {
        $params = [
            'id' => $cmid,
            'v' => (int)($this->instance->timemodified ?? time()),
        ];

        if ($this->localfile !== null) {
            $params['fh'] = substr($this->localfile->get_contenthash(), 0, 12);
        }
        if ($streammode !== null && $streammode !== '') {
            $params['stream'] = clean_param($streammode, PARAM_ALPHA);
        }

        return new \moodle_url('/mod/videoplayer/protected.php', $params);
    }

    /**
     * Safe browser filename.
     *
     * @return string
     */
    public function filename(): string {
        $name = clean_filename(format_string($this->instance->name, true, ['context' => $this->context]));
        if ($name === '') {
            $name = 'elearning-stream';
        }

        $extension = $this->is_pdf() ? 'pdf' : ($this->is_managed_video() ? 'mp4' : '');
        if ($extension !== '' && !preg_match('/\.' . preg_quote($extension, '/') . '$/i', $name)) {
            $name .= '.' . $extension;
        }

        return $name;
    }

    /**
     * Safe content type.
     *
     * @return string
     */
    public function mimetype(): string {
        if ($this->is_pdf()) {
            return 'application/pdf';
        }
        if ($this->is_managed_video()) {
            return 'video/mp4';
        }

        return 'application/octet-stream';
    }
}
