<?php

/**
 * Permet d'executer la generation des fichiers dans certaines conditions.
 */
function hook_layoutgenentitystyles_presave_alter_status($status) {
  if (!$condition)
    $status = false;
}