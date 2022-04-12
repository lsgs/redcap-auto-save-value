<?php
/**
 * REDCap External Module: Auto-Save Value 
 * Action tags to trigger automatic saving of values during data entry.
 * @author Luke Stevens, Murdoch Children's Research Institute
 */
if (is_null($module) || !($module instanceof MCRI\AutoSaveValue\AutoSaveValue)) { exit(0); }

try {
    $rtn = $module->saveValue();
} catch (\Exception $ex) {
    $rtn = $ex->getMessage();
} 
echo $rtn;