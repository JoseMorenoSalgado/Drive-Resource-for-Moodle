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

use mod_videoplayer\local\drive;
use mod_videoplayer\local\provider\bunny_stream;

/**
 * Normalised, browser-safe description of a Drive Resource instance.
 *
 * The descriptor deliberately exposes only Moodle protected endpoints. Google
 * file ids and upstream URLs remain server-side implementation details.
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

    /** @var string Canonical source. */
    private string $source;

    /** @var string Canonical type. */
    private string $type;

    /** @var string|null Google Drive file id. */
    private ?string $fileid;

    /** @var \stored_file|null Local protected PDF. */
    private ?\stored_file $localfile;

    /**
     * Constructor.
     *
     * @param \stdClass $instance
     * @param \context_module $context
     * @param string $source
     * @param string $type
     * @param string|null $fileid
     * @param \stored_file|null $localfile
     */
    private function __construct(
        \stdClass $instance,
        \context_module $context,
        string $source,
        string $type,
        ?string $fileid,
        ?\stored_file $localfile
    ) {
        $this->instance = $instance;
        $this->context = $context;
        $this->source = $source;
        $this->type = $type;
        $this->fileid = $fileid;
        $this->localfile = $localfile;
    }

    /**
     * Build a descriptor from an activity instance.
     *
     * @param \stdClass $instance
     * @param \context_module $context
     * @return self
     */
    public static function from_instance(\stdClass $instance, \context_module $context): self {
        $source = clean_param($instance->source ?? drive::SOURCE_GOOGLEDRIVE, PARAM_ALPHANUMEXT);

        if ($source === drive::SOURCE_LOCALPDF) {
            $localfile = videoplayer_get_localpdf_file($context);
            return new self(
                $instance,
                $context,
                drive::SOURCE_LOCALPDF,
                'pdf',
                null,
                $localfile ?: null
            );
        }

        if ($source === bunny_stream::SOURCE) {
            return new self(
                $instance,
                $context,
                bunny_stream::SOURCE,
                'video',
                null,
                null
            );
        }

        $source = drive::SOURCE_GOOGLEDRIVE;
        $url = trim((string)($instance->videourl ?? ''));
        $fileid = drive::is_supported_url($url) ? drive::extract_file_id($url) : null;
        $type = drive::resolve_record_type($instance);

        return new self($instance, $context, $source, $type, $fileid, null);
    }

    /**
     * Resource source.
     *
     * @return string
     */
    public function source(): string {
        return $this->source;
    }

    /**
     * Whether this resource is managed by Bunny Stream through WHMCS.
     *
     * @return bool
     */
    public function is_bunny_stream(): bool {
        return $this->source === bunny_stream::SOURCE;
    }

    /**
     * Managed provider asset identifier.
     *
     * This value is server-side state and is not exported to learner templates.
     *
     * @return string|null
     */
    public function provider_asset_id(): ?string {
        $assetid = trim((string)($this->instance->providerassetid ?? ''));
        return bunny_stream::is_valid_asset_id($assetid) ? $assetid : null;
    }

    /**
     * Managed provider processing status.
     *
     * @return string
     */
    public function provider_status(): string {
        return bunny_stream::normalise_status((string)($this->instance->providerstatus ?? ''));
    }

    /**
     * Canonical resource type.
     *
     * @return string
     */
    public function type(): string {
        return $this->type;
    }

    /**
     * Whether this resource can be rendered with local PDF.js.
     *
     * Google Docs, Sheets and Slides are exported to PDF server-side.
     *
     * @return bool
     */
    public function is_pdf_like(): bool {
        return drive::is_pdf_type($this->type);
    }

    /**
     * Whether this is a video resource.
     *
     * @return bool
     */
    public function is_video(): bool {
        return $this->type === 'video';
    }

    /**
     * Whether this is an audio resource.
     *
     * @return bool
     */
    public function is_audio(): bool {
        return $this->type === 'audio';
    }

    /**
     * Whether this is an image resource.
     *
     * @return bool
     */
    public function is_image(): bool {
        return $this->type === 'image';
    }

    /**
     * Google Drive file id for server-side resolution.
     *
     * @return string|null
     */
    public function fileid(): ?string {
        return $this->fileid;
    }

    /**
     * Local Moodle File API object when source=localpdf.
     *
     * @return \stored_file|null
     */
    public function local_file(): ?\stored_file {
        return $this->localfile;
    }

    /**
     * Whether the descriptor points to a usable resource.
     *
     * @return bool
     */
    public function is_available(): bool {
        if ($this->source === drive::SOURCE_LOCALPDF) {
            return $this->localfile !== null;
        }
        if ($this->source === bunny_stream::SOURCE) {
            return $this->provider_asset_id() !== null;
        }
        return $this->fileid !== null && $this->fileid !== '';
    }

    /**
     * Moodle-only protected resource URL for all managed sources.
     *
     * @param int $cmid Course module id.
     * @param string|null $streammode Optional video stream mode.
     * @return \moodle_url
     */
    public function protected_url(int $cmid, ?string $streammode = null): \moodle_url {
        $params = [
            'id' => $cmid,
            'v' => (int)($this->instance->timemodified ?? time()),
        ];

        if ($this->localfile) {
            $params['fh'] = substr($this->localfile->get_contenthash(), 0, 12);
        }
        if ($streammode !== null && $streammode !== '') {
            $params['stream'] = clean_param($streammode, PARAM_ALPHA);
        }

        return new \moodle_url('/mod/videoplayer/protected.php', $params);
    }

    /**
     * Safe browser filename. No upstream identifiers are exposed.
     *
     * @return string
     */
    public function filename(): string {
        $name = clean_filename(format_string($this->instance->name, true, ['context' => $this->context]));
        if ($name === '') {
            $name = 'drive-resource';
        }

        $extension = $this->extension();
        if ($extension !== '' && !preg_match('/\.' . preg_quote($extension, '/') . '$/i', $name)) {
            $name .= '.' . $extension;
        }

        return $name;
    }

    /**
     * Default MIME type for this resource.
     *
     * @return string
     */
    public function mimetype(): string {
        return drive::default_mimetype($this->type);
    }

    /**
     * Appropriate browser filename extension.
     *
     * @return string
     */
    private function extension(): string {
        if ($this->is_pdf_like()) {
            return 'pdf';
        }
        return match ($this->type) {
            'video' => 'mp4',
            'audio' => 'mp3',
            'image' => 'jpg',
            default => '',
        };
    }
}
