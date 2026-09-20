<?php
// This file is part of Moodle - http://moodle.org/

namespace mod_videoplayer\output;

/**
 * Output renderer for Drive Resource.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class renderer extends \plugin_renderer_base {

    /**
     * Render a learner resource view using the type-specific template selected
     * by the view model.
     *
     * @param resource_view $view
     * @return string
     */
    public function render_resource_view(resource_view $view): string {
        return $this->render_from_template(
            $view->template_name(),
            $view->export_for_template($this)
        );
    }

    /**
     * Render an invalid resource message.
     *
     * @return string
     */
    public function render_invalid_resource(): string {
        return \html_writer::div(get_string('invaliddriveurl', 'mod_videoplayer'), 'alert alert-danger');
    }
}
