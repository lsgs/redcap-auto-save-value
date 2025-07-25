********************************************************************************
# Auto-Save Value

Luke Stevens, Murdoch Children's Research Institute https://www.mcri.edu.au

[https://github.com/lsgs/redcap-auto-save-value/](https://github.com/lsgs/redcap-auto-save-value/)

********************************************************************************
## Summary

Action tags to trigger automatic saving of field values during data entry:
- `@AUTOSAVE` Auto-save the field's value when it is updated (in either data entry or survey mode).
- `@AUTOSAVE-FORM` Auto-save in data entry mode only, not in survey mode.
- `@AUTOSAVE-SURVEY` Auto-save in survey mode only, no in data entry mode.
- `@AUTOSAVE-FORM-HIDEICON` As `@AUTOSAVE-FORM`, but suppress the field's save icon where in data entry mode it would normally be shown.
- `@AUTOSAVE-SURVEY-SHOWICON` As `@AUTOSAVE-SURVEY`, but show the field's save icon where in survey mode it would normally be suppressed.

### Notes
- Auto-save cannot occur until the record exists for values to be saved to. This means that auto-save cannot work on the first page of a public survey, or when creating a new record.
- Only one tag is required per field: you do not need to use both `@AUTOSAVE-SURVEY` _and_ `@AUTOSAVE-SURVEY-SHOWICON`, for example. `@AUTOSAVE-SURVEY-SHOWICON` is sufficient alone for an auto-save field on a survey with icon shown.
- The auto-save tags do not operate in Draft Preview mode.
- The auto-save tags do not operate when previewing an instrument with a record data in the Online Designer using the "Preview Instrument" external module.

## Limitations

The following field types are currently **\*not supported\***:
- Text fields with ontology lookup
- Calculated fields (including text fields with `@CALCDATE()` and `@CALCTEXT()`)
- Checkbox
- File upload
- Signature
- Slider

## Example

<img alt="demonstration of auto-saving values on page load and after update" src="https://redcap.mcri.edu.au/surveys/index.php?pid=14961&__passthru=DataEntry%2Fimage_view.php&doc_id_hash=645acfe62abcae8fff3748da04ee1f1e9e67b52d&id=2143399&s=ZQvnHwJkw3zdumAQ&page=file_page&record=17&event_id=47634&field_name=thefile&instance=1" />

********************************************************************************