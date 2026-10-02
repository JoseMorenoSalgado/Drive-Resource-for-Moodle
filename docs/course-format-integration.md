# Future course-format integration contract

## Decision

The future Elearning Cloud course experience must be implemented as a separate
Moodle course-format plugin, not inside `mod_videoplayer`.

Recommended component name:

```text
format_elearningstream
```

A course format and an activity module have different Moodle responsibilities.
Keeping them separate prevents theme/course-layout changes from destabilising
protected media delivery, progress, billing or provider lifecycle logic.

## Allowed integration surface

The course format must use Moodle core APIs to discover and render activities:

- `get_fast_modinfo()`;
- `course_modinfo` / `cm_info`;
- course sections and availability APIs;
- `completion_info`;
- standard activity URLs from `cm_info->url`;
- Moodle capability/context APIs;
- Moodle output/renderers and templates.

The format may identify Elearning Stream activities by:

```text
cm_info->modname === 'videoplayer'
```

This is the stable Moodle component identifier.

## Forbidden coupling

The course-format plugin must never:

- query `videoplayer*` tables directly;
- read `providerassetid` or provider upload ids;
- read Elearning Stream service tokens;
- call WHMCS/provider endpoints directly;
- construct `protected.php` URLs itself;
- duplicate progress/completion calculations;
- depend on internal classes such as the provider adapter.

Opening an activity must use the normal Moodle activity URL. Completion state
must come from Moodle Completion API.

## Stable information exposed through Moodle

`videoplayer_get_coursemodule_info()` supplies Moodle with the activity name,
optional formatted intro and custom completion rule information. The course
format should consume the resulting `cm_info`, not call the callback directly.

This gives the future format freedom to implement cards, modules, units,
progress navigation and responsive course UX while Elearning Stream remains
responsible only for the resource itself.

## Future evolution

If the format later needs richer cross-plugin metadata, add a small documented
public integration contract first. Prefer a Moodle hook/API or read-only
provider interface over direct database access.

Any public contract must be:

- provider-neutral;
- versioned;
- covered by PHPUnit;
- documented in this file;
- backward compatible for at least one stable release line.

## Dependency strategy

The course format should remain usable when Elearning Stream is not installed.
Elearning Stream-specific enhancements should be capability-detected and
degrade to standard Moodle activity rendering.

This allows the format to be published and updated independently in the Moodle
plugin directory.
