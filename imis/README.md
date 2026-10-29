# iMIS-side pieces

Pages and content that live in iMIS, not in Moodle. They are kept here so they are
versioned with the plugin, and are left out of the plugin's release zip
(`.gitattributes`).

## `tutorial-groups.html`: Tutorial Groups tab

The **Tutorial Groups** tab on the iMIS *Specific Event Dashboard*. Staff use it to
build an event's tutorial groups and mark each group's tutors. It reads and writes
the `MoodleTutorialGroup` panel rows that the plugin's tutorial group sync mirrors
into Moodle (changeover checklist §T).

What it does, for the event in the page address (`EventKey`):
- Shows the event's active registrants with their current group and whether they
  are a tutor, plus a summary of groups and tutors.
- **Add to group:** puts the ticked people in the named group, creating or moving
  their row. Duplicate rows for the event are tidied. A tutor moved to another group
  stays a tutor.
- **Remove from group:** deletes their rows for the event.
- **Mark as tutor / Unmark tutor:** sets the `Tutor` checkbox on every row the ticked
  people have for the event. They must already be in a group.
- If a refresh fails, changes are disabled until **Refresh** loads the data again, so
  nothing is saved from out-of-date rows.
- Checks that the event maps to a Moodle course (for SCCA, a `{EventKey}/REG` row
  in `MOODLE_PRODUCTS`, through the `MoodleCourseLookup` query) and disables changes
  if it doesn't. As in the Moodle sync, a returned row with a different `Code` (such as
  `{EventKey}/REGNZ`) is ignored, and the `Description` must be a course id.

It needs:
- the `MoodleTutorialGroup` panel source, with the properties `Code`,
  `Moodlegroupname` and `Tutor` (Yes/No or text; the page works with either);
- the queries `$/_SCCA/Events/Activeregistrations` (prompt: event key) and
  `$/_SCCA/Events/MoodleCourseLookup` (prompt: `{EventKey}/REG`).

These are SCCA's names. For another iMIS, change them in the **Site settings**
block at the top of the script (`REGISTRANT_IQA`, `LOOKUP_IQA`, `LOOKUP_SUFFIX`,
`PANEL`, `FIELD_CODE`, `FIELD_GROUP`, `TUTOR`) to match yours and the plugin's
settings. The page checks the course mapping through the lookup query, so it
needs one even when the plugin maps courses by Moodle course ID number.

### Adding `Tutor` to a panel that already has rows

Rows created before the `Tutor` property was added have an empty (NULL) Tutor.
iMIS can still list them, but reading, saving or deleting one of those rows on
its own fails with HTTP 500 ("An error occurred"). In this page, Mark as tutor,
moves and Remove then fail for those people, and the browser console shows, for
example, `PUT /api/MoodleTutorialGroup/~20007|2 failed 500`. Rows created after
the property was added work. Fix the old rows straight after adding the
property, using either:
- **SQL:** set `Tutor = 0` where it is NULL in the panel's table, and give the
  column a default of 0; or
- **No SQL access:** add `Tutor` as a text property instead of Yes/No. iMIS loads
  an empty text value without error, and the Moodle sync counts `True`, `Yes` or
  `1` as ticked. The page needs no change: iMIS stores its true/false value in a
  text property too.

To check: on a person who isn't in a group, use Add to group and then Mark as
tutor. That works, while an older row fails until it has been fixed.

### Installing or updating

1. In iMIS, go to *RiSE → Page builder* and open the event dashboard page.
2. On the **Tutorial Groups** tab, edit its **Content HTML** item and replace its
   content with this file.
3. Save and publish.

The script contains no `&`, `<` or `>` (it builds `&` with `AMP` and uses nested
`if`s instead of `&&`), because the iMIS content editor escapes them. Keep it that
way when editing.
