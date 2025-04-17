<?php
/**
 * REDCap External Module: Auto-Save Value 
 * Action tags to trigger automatic saving of values during data entry.
 * 
 * TODO
 * - Add action tags to AT dialogs
 * - Extend implementation for other field types
 * - Handle update success, no change, error message/log
 * - Decide how to handle calc/calcdate/calctext fields
 * 
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
    protected $noauth;
    protected $project_id;
    protected $record;
    protected $event_id;
    protected $instance;
    protected $jsObjName;
    protected $fieldsSaveOnLoad;
    protected $fieldsSaveOnEdit;
    protected $autoSaveFields;
    static $expectedPostParams = [ 'project_id','record','event_id','instance','field','value'];

    public function redcap_data_entry_form($project_id, $record=null, $instrument, $event_id, $group_id=null, $repeat_instance=1) {
        global $Proj;
        $this->noauth = false;
        $this->project_id = $project_id;
        $this->record = $record;
        $this->event_id = $event_id;
        $this->instance = $repeat_instance;
        $pf=array_keys($Proj->forms[$instrument]['fields']);
        $this->includeSaveFunctions($pf);
    }

    public function redcap_survey_page($project_id, $record=null, $instrument, $event_id, $group_id=null, $survey_hash=null, $response_id=null, $repeat_instance=1) {
        global $pageFields;
        $this->noauth = true;
        $this->project_id = $project_id;
        $this->record = $record;
        $this->event_id = $event_id;
        $this->instance = $repeat_instance;
        $pf=$pageFields[$this->escape($_GET['__page__'])];
        $this->includeSaveFunctions($pf);
    }

    protected function filterPageFieldsByTag($pageFields, $tag) {
        global $Proj;
        $taggedFields = array();
        foreach ($pageFields as $f) {
            $annotation = $Proj->metadata[$f]['misc'];
            if (preg_match("/(^|\s)$tag($|\s)/",$annotation)) {
                $taggedFields[] = $f;
            }
        }
        return $taggedFields;
    }

    protected function includeSaveFunctions($pageFields) {
        $this->autoSaveFields = $this->filterPageFieldsByTag($pageFields, static::TAG_AUTOSAVE);
        if ($this->noauth) {
            $this->autoSaveFields = array_merge($this->autoSaveFields, $this->filterPageFieldsByTag($pageFields, static::TAG_AUTOSAVE_SURVEY));
        } else {
            $this->autoSaveFields = array_merge($this->autoSaveFields, $this->filterPageFieldsByTag($pageFields, static::TAG_AUTOSAVE_FORM));
        }
        if (count($this->autoSaveFields) === 0 ) return;
        
        $this->initializeJavascriptModuleObject();
        $this->jsObjName = $this->getJavascriptModuleObjectName();
        ?>
        <!-- Auto-Save Value external module: start-->
        <style type="text/css">
            .asv-save { color: green; display: none; }
            .asv-fail { color: red; display: none; }
        </style>
        <script type="text/javascript">
            $(function(){
                var module = <?=$this->jsObjName?>;
                module.autoSaveFields = JSON.parse('<?=json_encode($this->autoSaveFields)?>');

                module.appendIcons = function(field) {
                    $('[name='+field+']').after('<i class="fas fa-save mx-1 asv-save" title="Saved"></i><i class="fas fa-times mx-1 asv-fail" title="Save failed"></i>')
                }

                module.fieldChange = function(field) {
                    $('[name='+field+']').on('change', function() {
                        module.save(field, module.readFieldValue(field));
                    });
                }

                module.saveSuccess = function(field) {
                    $('[name='+field+']').parent('td').find('.asv-save').fadeIn(1000);
                    $('[name='+field+']').removeClass('calcChanged');
                };
                module.saveFailed = function(field) {
                    $('[name='+field+']').parent('td').find('.asv-fail').fadeIn(1000);
                };
                
                module.save = function(field, value){
                    module.ajax('<?=static::AUTOSAVE_ACTION?>', [field, value]).then(function(response) {
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

                module.readFieldValue = function(field) {
                    return $('[name='+field+']').eq(0).val();
                };

                module.init = function() {
                    module.autoSaveFields.forEach((asf) => { 
                        module.appendIcons(asf);
                        module.fieldChange(asf);
                        if (dataEntryFormValuesChanged) module.save(asf, module.readFieldValue(asf)); // save fields with @DEFAULT or @SETVALUE values (including empty)
                    });
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
            $payload = $this->escape(array_values($payload));

            if (!is_string($payload[0]) || !array_key_exists($payload[0], $Proj->forms[$instrument]['fields'])) throw new \Exception('Unexpected field name '.json_encode($payload[0]));
            $field = $payload[0];

            if (!isset($payload[1])) throw new \Exception("Field $field no value supplied");
            $value = $payload[1];

            $saveData = array(
                $Proj->table_pk => $this->record,
                $field => $this->formatSaveValue($field, $value)
            );

            if (\REDCap::isLongitudinal()) {
                $saveData['redcap_event_name'] = \REDCap::getEventNames(true, false, $this->event_id);
            }

            if (!empty($this->instance) && $this->instance > 1) {
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
        return $value;
    }
}