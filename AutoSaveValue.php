<?php
/**
 * REDCap External Module: Auto-Save Value 
 * Action tags to trigger automatic saving of values during data entry.
 * @author Luke Stevens, Murdoch Children's Research Institute
 */
namespace MCRI\AutoSaveValue;

use ExternalModules\AbstractExternalModule;

class AutoSaveValue extends AbstractExternalModule
{
    protected const AUTOSAVE_ACTION = 'save';
    protected const TAG_AUTOSAVE = '@AUTOSAVE';
    protected const TAG_AUTOSAVE_FORM = '@AUTOSAVE-FORM';
    protected const TAG_AUTOSAVE_SURVEY = '@AUTOSAVE-SURVEY';
    protected const TAG_AUTOSAVE_FORM_HIDEICON = '@AUTOSAVE-FORM-HIDEICON';
    protected const TAG_AUTOSAVE_SURVEY_SHOWICON = '@AUTOSAVE-SURVEY-SHOWICON';
    protected $noauth;
    protected $project_id;
    protected $record;
    protected $event_id;
    protected $instrument;
    protected $instance;
    protected $jsObjName;
    protected $fieldsSaveOnLoad;
    protected $fieldsSaveOnEdit;
    protected $autoSaveFields;
    protected $autoSaveIconSwapFields;
    protected $autoSaveOnLoadFields;
    protected $defaultNoAutoSaveFields;
    static $SupportedFieldTypes = [
        // 'calc',
        // 'checkbox',
        // 'file', // includes signature
        'radio',
        'select', // includes auto-complete
        // 'slider',
        'sql',
        'text', // excludes ontology
        'textarea',
        'truefalse',
        'yesno'
    ];

    public function redcap_data_entry_form(int $project_id, string $record, string $instrument, int $event_id, ?int $group_id, int $repeat_instance=1) {
        if (is_null($record)) return; // cannot autosave until record exists (not on new record or first page of public survey)
        if (isset($_GET['em_preview_instrument']) && $_GET['em_preview_instrument']=='1') return; // don't save if previewing from designer using Preview Instrument EM
        global $Proj, $draft_preview_enabled;
        if ($draft_preview_enabled) return; // no auto-save in draft mode preview
        $this->noauth = false;
        $this->project_id = $project_id;
        $this->record = $record;
        $this->event_id = $event_id;
        $this->instrument = $instrument;
        $this->instance = $repeat_instance;
        $pf=(isset($Proj->forms[$this->instrument]['fields'])) ? array_keys($Proj->forms[$this->instrument]['fields']) : array();
        $this->includeSaveFunctions($pf);
    }

    public function redcap_survey_page(int $project_id, string $record, string $instrument, int $event_id, int $group_id, string $survey_hash, ?int $response_id, int $repeat_instance = 1) {
        if (is_null($record)) return; // cannot autosave until record exists (not on new record or first page of public survey)
        global $pageFields;
        $this->noauth = true;
        $this->project_id = $project_id;
        $this->record = $record;
        $this->event_id = $event_id;
        $this->instrument = $instrument;
        $this->instance = $repeat_instance;
        $pf=(isset($_GET['__page__'])) ? $pageFields[$this->escape($_GET['__page__'])] : array();
        $this->includeSaveFunctions($pf);
    }

    protected function filterPageFieldsByTag($pageFields, $tag) {
        global $Proj;
        $taggedFields = array();
        foreach ($pageFields as $f) {
            if (!in_array($Proj->metadata[$f]['element_type'], static::$SupportedFieldTypes)) continue;

            $rawAnnotation = $Proj->metadata[$f]['misc'];
            $annotation = \Form::replaceIfActionTag($rawAnnotation, $Proj->project_id, $this->record, $this->event_id, $this->instrument, $this->instance);
            if (preg_match("/(^|\s)$tag($|\s|=)/",$annotation)) {

                if (!($Proj->metadata[$f]['element_type']=='text' && (preg_match("/@CALCTEXT|@CALCDATE/",$annotation)))) { 
                    $taggedFields[] = $f;
                }
            }
        }
        return $taggedFields;
    }

    protected function includeSaveFunctions($pageFields) {
        if (empty($pageFields)) return;
        $this->autoSaveFields = $this->filterPageFieldsByTag($pageFields, static::TAG_AUTOSAVE);
        if ($this->noauth) {
            $this->autoSaveIconSwapFields = $this->filterPageFieldsByTag($pageFields, static::TAG_AUTOSAVE_SURVEY_SHOWICON);
            $this->autoSaveFields = array_merge($this->autoSaveFields, $this->autoSaveIconSwapFields, $this->filterPageFieldsByTag($pageFields, static::TAG_AUTOSAVE_SURVEY));
        } else {
            $this->autoSaveIconSwapFields = $this->filterPageFieldsByTag($pageFields, static::TAG_AUTOSAVE_FORM_HIDEICON);
            $this->autoSaveFields = array_merge($this->autoSaveFields, $this->autoSaveIconSwapFields, $this->filterPageFieldsByTag($pageFields, static::TAG_AUTOSAVE_FORM));
        }
        if (count($this->autoSaveFields) === 0 ) return;
        $this->autoSaveFields = array_unique($this->autoSaveFields); // get rid of any duplicates e.g. if have both  @AUTOSAVE-SURVEY and @AUTOSAVE-SURVEY-SHOWICON
        
        $fieldsDefaultOrSetvalue = array();
        $pageHasData = \Records::fieldsHaveData($this->record, $pageFields, $this->event_id, $this->instance);
        if (!$pageHasData) {
            foreach (array('@DEFAULT','@TODAY','@TODAY-SERVER','@TODAY-UTC','@NOW','@NOW-SERVER','@NOW-UTC') as $tag) {
                $fieldsDefaultOrSetvalue = array_merge($fieldsDefaultOrSetvalue, $this->filterPageFieldsByTag($pageFields, $tag));
            }
        }
        $fieldsDefaultOrSetvalue = array_unique(array_merge($fieldsDefaultOrSetvalue, $this->filterPageFieldsByTag($pageFields, '@SETVALUE')));
        $this->autoSaveOnLoadFields = array_values(array_intersect($this->autoSaveFields, $fieldsDefaultOrSetvalue));
        $this->defaultNoAutoSaveFields = array_values(array_diff($fieldsDefaultOrSetvalue, $this->autoSaveFields));

        $this->initializeJavascriptModuleObject();
        $this->jsObjName = $this->getJavascriptModuleObjectName();
        ?>
        <!-- Auto-Save Value external module: start-->
        <style type="text/css">
            @keyframes pulse {
                0% { transform: scale(1); }
                50% { transform: scale(0.8); }
                100% { transform: scale(1); }
            }
            .asv-default { color: #888; }
            .asv-save { color: green; display: none; }
            .asv-fail { color: red; display: none; }
            .pulse { animation: pulse 1s infinite; }
        </style>
        <script type="text/javascript">
            $(function(){
                var module = <?=$this->jsObjName?>;
                module.isSurvey = <?=($this->noauth)?1:0;?>;
                module.autoSaveFields = JSON.parse('<?=json_encode($this->autoSaveFields)?>');
                module.iconSwapFields = JSON.parse('<?=json_encode($this->autoSaveIconSwapFields)?>');
                module.autoSaveOnLoadFields = JSON.parse('<?=json_encode($this->autoSaveOnLoadFields)?>');
                module.defaultNoAutoSaveFields = JSON.parse('<?=json_encode($this->defaultNoAutoSaveFields)?>');
                module.singleFieldChange = false;
                module.iconSpan = '<span class="asv-icons"><i class="fas fa-save mx-1 asv-default" title="Auto-save field value on change"></i><i class="fas fa-save mx-1 asv-save" title="Saved"></i><i class="fas fa-times mx-1 asv-fail" title="Save failed"></i></span>'

                module.findInput = function(field) {
                    return $('[name='+field+']:first');
                };

                module.findGroupInputs = function(field) {
                    let input = $('[name='+field+']:first');
                    let type = module.getFieldType(field)
                    if (type=='radio') {
                        return $('[name='+field+'___radio]'); // group of input type="radio", one per value
                    } else {
                        return $(input);
                    }
                };

                module.getFieldType = function(field) {
                    let f = module.findInput(field); type = '';
                    let elemTag = $(f).eq(0).prop('nodeName');
                    let elemType = $(f).eq(0).prop('type')
                    if (elemTag=='INPUT' && $(f).eq(0).hasClass('hiddenradio')) {
                        type = 'radio'; // covers yesno and truefalse too
                    } else if (elemTag=='INPUT' && $(f).eq(0).hasClass('autosug-ont-field')) {
                        type = 'text-ontology';
                    } else if (elemTag=='INPUT' && elemType=='text') {
                        type = 'text';
                    } else if (elemTag=='SELECT' && $(f).eq(0).hasClass('rc-autocomplete')) {
                        type = 'dropdown-autocomplete';
                    } else if (elemTag=='SELECT') {
                        type = 'dropdown';
                    } else if (elemTag=='TEXTAREA') {
                        type = 'notes';
                    }
                    return type;
                };

                module.appendIcons = function(field) {
                    let type = module.getFieldType(field);
                    let span = module.iconSpan;
                    if (module.isSurvey && !module.iconSwapFields.includes(field) // hide icons on survey unless directed to show
                            || !module.isSurvey && module.iconSwapFields.includes(field) ) { // hide icons on form when directed to hide
                        span = span.replace('class="asv-icons"', 'class="asv-icons d-none"');
                    }
                    if (type=='dropdown-autocomplete') {
                        module.findInput(field).closest('span[data-kind=field-value]').find('div').append(span);
                    } else if (type=='radio') {
                        let radioButtons = module.findGroupInputs(field);
                        if ($(radioButtons).eq(0).parent('td.choicematrix').length) {
                            $(radioButtons).eq(0).closest('table[role=presentation]').siblings('.resetLinkParent:first').prepend(span);
                        } else {
                            $(radioButtons).eq(0).closest('span[data-kind=field-value]').siblings('.resetLinkParent:first').prepend(span);
                        }
                    } else if (type=='notes') {
                        module.findInput(field).closest('span[data-kind=field-value]').siblings('.expandLinkParent:first').prepend(span);
                    } else if (type=='text-ontology') {
                        // is working but suppressed: module.findInput(field).after(span);
                    } else {
                        module.findInput(field).after(span);
                    }
                }

                module.getFieldIcon = function(field, icon) {
                    let type = module.getFieldType(field);
                    if (type=='radio') {
                        return module.findInput(field).closest('[data-kind=field-value]').siblings('.resetLinkParent:first').find('i.asv-'+icon);
                    } else if (type=='notes') {
                        return module.findInput(field).closest('[data-kind=field-value]').siblings('.expandLinkParent:first').find('i.asv-'+icon);
                    } else {
                        return module.findInput(field).closest('[data-kind=field-value]').find('i.asv-'+icon);
                    }
                };

                module.addUpdateHander = function(field) {
                    let field_input = module.findInput(field);
                    let field_type = module.getFieldType(field);
                    if (field_type=='radio') {
                        let radioButtons = module.findGroupInputs(field);
                        radioButtons.on('change', {field:field}, module.updateHandler); // field_input is group of inputs, one per choice
                    } else if (field_type=='dropdown-autocomplete') {
                        field_input.on('change', {field:field}, module.updateHandler);
                    } else if (field_type=='text-ontology') {
                        // not working: field_input.on('autocompletechange', {field:field}, module.updateHandler);
                    } else {
                        field_input.on('blur', {field:field}, module.updateHandler);
                    }
                };

                module.updateHandler = function(e) {
                    module.save(e.data.field, module.readFieldValue(e.data.field, this));
                };

                module.saveSuccess = function(field) {
                    module.getFieldIcon(field,'save').fadeIn(1000);
                    module.findInput(field).removeClass('calcChanged');
                    if (module.singleFieldChange) dataEntryFormValuesChanged = false; // only this autosave field has changed - will reset dataEntryFormValuesChanged to false after save
                };
                module.saveFailed = function(field) {
                    module.getFieldIcon(field,'fail').fadeIn(1000);
                };
                
                module.save = function(field, value){
                    module.getFieldIcon(field,'default').addClass('pulse').show();
                    module.getFieldIcon(field,'save').hide();
                    module.getFieldIcon(field,'fail').hide();
                    module.singleFieldChange = !dataEntryFormValuesChanged;
                    module.ajax('<?=static::AUTOSAVE_ACTION?>', [field, value]).then(function(response) {
                        module.getFieldIcon(field,'default').removeClass('pulse').hide();
                        if (response) {
                            module.saveSuccess(field);
                        } else {
                            module.saveFailed(field);
                        }
                    }).catch(function(err) {
                        console.log('Auto-save failed: field=\''+field+'\'; value=\''+value+'\': '+err);
                        module.saveFailed(field);
                    });
                };

                module.readFieldValue = function(field, triggerElement=null) {
                    if (triggerElement==null) {
                        triggerElement = module.findInput(field);
                    }
                    return $(triggerElement).eq(0).val();
                };

                module.init = function() {
                    module.autoSaveFields.forEach((asf) => { 
                        module.appendIcons(asf);
                        module.addUpdateHander(asf);
                    });
                    if (dataEntryFormValuesChanged && module.autoSaveOnLoadFields.length) {
                        // save fields with values from @DEFAULT, @TODAY, @NOW, or @SETVALUE (including empty)
                        module.autoSaveOnLoadFields.forEach(function(asf) {
                            module.save(asf, module.readFieldValue(asf));
                        });
                        if (module.defaultNoAutoSaveFields.length===0) {
                            // all auto-populated fields have been saved, no need for save prompt on leaving
                            dataEntryFormValuesChanged = false;
                        }
                    }
                };

                module.init();
            });
        </script>
        <!-- Auto-Save Value external module: end-->
        <?php
    }

    public function redcap_module_ajax($action, $payload, $project_id, $record, $instrument, $event_id, $repeat_instance, $survey_hash, $response_id, $survey_queue_hash, $page, $page_full, $user_id, $group_id) {
        global $Proj;
        $this->project_id = $project_id;
        $this->record = $record;
        $this->event_id = $event_id;
        $this->instance = $repeat_instance;
        $rtn = 0;

        try {
            if (!is_array($payload)) throw new \Exception('Unexpected payload '.$this->escape(json_encode($payload)));
            $payload = array_values($payload);

            if (!is_string($payload[0]) || !array_key_exists($payload[0], $Proj->forms[$instrument]['fields'])) throw new \Exception('Unexpected field name '.json_encode($payload[0]));
            $field = $this->escape($payload[0]);

            if (!isset($payload[1])) throw new \Exception("Field $field no value supplied");
            $value = $payload[1]; // nb do not use $this->escape() here because altering e.g. & in text values submitted to &amp; is undesirable 

            $saveData = array(
                $Proj->table_pk => $this->record,
                $field => $this->formatSaveValue($field, $value)
            );

            if (\REDCap::isLongitudinal()) {
                $saveData['redcap_event_name'] = \REDCap::getEventNames(true, false, $this->event_id);
            }

            if ($Proj->isRepeatingEvent($this->event_id)) {
                $saveData['redcap_repeat_instrument'] = '';
                $saveData['redcap_repeat_instance'] = $this->instance;
            } else if ($Proj->isRepeatingForm($this->event_id, $instrument)) {
                $saveData['redcap_repeat_instrument'] = $instrument;
                $saveData['redcap_repeat_instance'] = $this->instance;
            }

            $saveResult = \REDCap::saveData('json', json_encode(array($saveData)), 'overwrite');

            if (isset($saveResult['errors']) && !empty($saveResult['errors'])) {
                $detail = "Field: $field; Value: $value";
                $detail .= " \nErrors: ".print_r($saveResult['errors'], true);
                throw new \Exception($detail);
            } else {
                $rtn = 1;
            }
        } catch(\Throwable $th) {
            $title = "Auto-Save Value module";
            $detail = "Save failed: ".$th->getMessage();
            \REDCap::logEvent($title, $detail, '', $record, $event_id);
        }

        return $rtn;
    }

    public function formatSaveValue($field, $value) {
        global $Proj;
        $fieldType = $Proj->metadata[$field]['element_type'];
        $fieldValType = $Proj->metadata[$field]['element_validation_type'];

        if ($fieldType=='text' && strpos($fieldValType,'mdy')) {
            $value = \DateTimeRC::date_mdy2ymd($value);
        } else if ($fieldType=='text' && strpos($fieldValType,'dmy')) {
            $value = \DateTimeRC::date_dmy2ymd($value);
        }
        // nb do not use $this->escape() here because altering e.g. & in text values submitted to &amp; is undesirable 
        // save data gets validated and sanitised in REDCap::saveData()
        return $value;
    }
}