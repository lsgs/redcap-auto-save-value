<?php
/**
 * REDCap External Module: Auto-Save Value 
 * Action tags to trigger automatic saving of values during data entry.
 * 
 * TODO
 * - @SAVE-ON-EDIT 
 * - Add action tags to AT dialogs
 * - Framework v8 for CSRF protection
 *
 * @author Luke Stevens, Murdoch Children's Research Institute
 */
namespace MCRI\AutoSaveValue;

use ExternalModules\AbstractExternalModule;

class AutoSaveValue extends AbstractExternalModule
{
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
        $pf=array_keys($Proj->forms[$instrument]);
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
            if (preg_match("/$tag/",$annotation)) {
                $taggedFields[] = $f;
            }
        }
        return $taggedFields;
    }

    protected function includeSaveFunctions($pageFields) {
        $this->fieldsSaveOnLoad = $this->filterPageFieldsByTag($pageFields, '@SAVE-ON-PAGE-LOAD');
        $this->fieldsSaveOnEdit = array(); // TODO

        if (count($this->fieldsSaveOnLoad) + count($this->fieldsSaveOnEdit) === 0 ) return;
        
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
                var asv = <?=$this->jsObjName?>;
                asv.context = JSON.parse('<?=json_encode($context)?>');
                asv.saveUrl = '<?=$this->getUrl('save.php', $this->noauth);?>';
                asv.fieldsSaveOnLoad = JSON.parse('<?=json_encode($this->fieldsSaveOnLoad)?>');
                asv.fieldsSaveOnEdit = JSON.parse('<?=json_encode($this->fieldsSaveOnEdit)?>');

                asv.appendIcons = function(field) {
                    $('[name='+field+']').after('<i class="fas fa-save mx-1 asv-save" title="Saved"></i><i class="fas fa-times mx-1 asv-fail" title="Save failed"></i>')
                }

                asv.save = function(field){
                    asv.context.field = field;
                    asv.context.value = asv.readFieldValue(field);
                    $.post(
                        asv.saveUrl,
                        asv.context,
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

                asv.readFieldValue = function(field) {
                    return $('[name='+field+']').eq(0).val();
                };

                $(document).ready(function(){
                    asv.fieldsSaveOnLoad.forEach(asv.appendIcons)
                    asv.fieldsSaveOnEdit.forEach(asv.appendIcons)
                    asv.fieldsSaveOnLoad.forEach(asv.save)
                    //console.log(asv.fieldsSaveOnEdit);
                });
            });
        </script>
        <?php
    }

    public function saveValue() {
        global $Proj;
        if (!$this->readContext()) throw new \Exception("Invalid data submitted ".htmlspecialchars(json_encode($_POST),ENT_QUOTES));
        $saveData = array(
            $Proj->table_pk => $this->record,
            $this->field => $this->value
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
            $detail .= " \n".print_r($_POST, true);
            $detail .= " \n".print_r($saveResult['errors'], true);
            \REDCap::logEvent($title, $detail, '');
            $rtn = 0;
        } else {
            $rtn = 1;
        }
        return $rtn;
    }

    protected function readContext() {
        global $Proj;
        $ok = true;
        foreach (static::$expectedPostParams as $pp) {
            if (isset($_POST[$pp])) {
                $this->$pp = \htmlspecialchars($_POST[$pp]);
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