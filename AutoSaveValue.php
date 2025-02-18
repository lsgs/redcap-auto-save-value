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
    static $expectedPostParams = [ 'project_id','record','event_id','instance','field','value'];

    public function redcap_data_entry_form($project_id, $record=null, $instrument, $event_id, $group_id=null, $repeat_instance=1) {
        global $Proj;
        $this->noauth = false;
        $this->project_id = $project_id;
        $this->record = \htmlspecialchars($record, ENT_QUOTES);
        $this->event_id = $event_id;
        $this->instance = $repeat_instance;
        $pf=array_keys($Proj->forms[$instrument]['fields']);
        $this->includeSaveFunctions($pf);
    }

    public function redcap_survey_page($project_id, $record=null, $instrument, $event_id, $group_id=null, $survey_hash=null, $response_id=null, $repeat_instance=1) {
        global $pageFields;
        $this->noauth = true;
        $this->project_id = $project_id;
        $this->record = \htmlspecialchars($record, ENT_QUOTES);
        $this->event_id = $event_id;
        $this->instance = $repeat_instance;
        $pf=$pageFields[\htmlspecialchars($_GET['__page__'], ENT_QUOTES)];
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
        
        $context = array(
            'project_id' => $this->project_id, 
            'record' => $this->record,
            'event_id' => $this->event_id,
            'instance' => $this->instance,
            'redcap_csrf_token' => $this->getCSRFToken()
        );

        $this->initializeJavascriptModuleObject();
        $this->jsObjName = $this->getJavascriptModuleObjectName();
        ?>
        <style type="text/css">
            .asv-save { color: green; display: none; }
            .asv-fail { color: red; display: none; }
        </style>
        <script type="text/javascript">
            $(function(){
                var module = <?=$this->jsObjName?>;
                module.context = JSON.parse('<?=json_encode($context)?>');
                module.saveUrl = '<?=$this->getUrl('save.php', $this->noauth);?>';
                module.autoSaveFields = JSON.parse('<?=json_encode($this->autoSaveFields)?>');

                module.appendIcons = function(field) {
                    $('[name='+field+']').after('<i class="fas fa-save mx-1 asv-save" title="Saved"></i><i class="fas fa-times mx-1 asv-fail" title="Save failed"></i>')
                }

                module.fieldChange = function(field) {
                    $('[name='+field+']').on('change', function(){
                        module.save(field);
                    });
                }

                module.save = function(field){
                    module.context.field = field;
                    module.context.value = module.readFieldValue(field);
                    $.post(
                        module.saveUrl,
                        module.context,
                        function(rtn) {
                            if (rtn) {
                                $('[name='+field+']').parent('td').find('.asv-save').fadeIn(1000);
                                $('[name='+field+']').removeClass('calcChanged');
                            } else {
                                $('[name='+field+']').parent('td').find('.asv-fail').fadeIn(1000);
                            }
                        }
                    );
                };

                module.readFieldValue = function(field) {
                    return $('[name='+field+']').eq(0).val();
                };

                module.init = function() {
                    module.autoSaveFields.forEach((asf) => { 
                        module.appendIcons(asf);
                        module.fieldChange(asf);
                        if(dataEntryFormValuesChanged) module.save(asf); // save fields with default or @SETVALUE values (including empty)
                    });
                };

                module.init();
            });
        </script>
        <?php
    }

    public function saveValue() {
        global $Proj;
        if (!$this->readContext()) throw new \Exception("Invalid data submitted ".htmlspecialchars(json_encode($_POST),ENT_QUOTES));
        $saveData = array(
            $Proj->table_pk => $this->record,
            $this->field => $this->formatSaveValue($this->field, $this->value)
        );

        if (\REDCap::isLongitudinal()) {
            $saveData['redcap_event_name'] = \REDCap::getEventNames(true, false, $this->event_id);
        }

        if (!empty($this->instance) && $this->instance > 1) {
            $saveData['redcap_repeat_instance'] = $this->instance;
        }

        $saveResult = \REDCap::saveData('json', json_encode(array($saveData)), 'overwrite');

        if (count($saveResult['errors'])>0) {
            $title = "Auto-Save Value module";
            $detail = "Save failed: ";
            $detail .= " \n".htmlspecialchars(print_r($_POST, true), ENT_QUOTES);
            $detail .= " \n".print_r($saveResult['errors'], true);
            \REDCap::logEvent($title, $detail, '');
            $rtn = 0;
        } else {
            $rtn = 1;
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

    protected function readContext() {
        global $Proj;
        $ok = true;
        foreach (static::$expectedPostParams as $pp) {
            if (isset($_POST[$pp])) {
                $this->$pp = \htmlspecialchars($_POST[$pp], ENT_QUOTES);
            } else {
                $ok = false;
            }
        }
        if ($this->project_id!=PROJECT_ID) $ok = false;
        if (!array_key_exists($this->event_id, $Proj->eventInfo)) $ok = false;
        if (!array_key_exists($this->field, $Proj->metadata)) $ok = false;
        return $ok;
    }
}