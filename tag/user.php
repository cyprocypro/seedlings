<?php

require_once('../config.php');
require_once('lib.php');

$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);
$tag = optional_param('tag', '', PARAM_TAG);

require_login();

if (empty($CFG->usetags)) {
    print_error('tagdisabled');
}

if (isguestuser()) {
    print_error('noguest');
}

if (!confirm_sesskey()) {
    print_error('sesskey');
}

$usercontext = context_user::instance($USER->id);

// Either tag or tagid is required.
if (empty($tag) && !$id) {
    print_error('invaliddata');
}

switch ($action) {
    case 'addinterest':
        if (empty($tag) && $id) { // for backward-compatibility (people saving bookmarks, mostly..)
            $tag = tag_get_name($id);
        }

        tag_set_add('user', $USER->id, $tag, 'core', $usercontext->id);

        redirect($CFG->wwwroot.'/tag/index.php?tag='. rawurlencode($tag));
        break;

    case 'removeinterest':
        if (empty($tag) && $id) { // for backward-compatibility (people saving bookmarks, mostly..)
            $tag = tag_get_name($id);
        }

        tag_set_delete('user', $USER->id, $tag, 'core', $usercontext->id);

        redirect($CFG->wwwroot.'/tag/index.php?tag='. rawurlencode($tag));
        break;

    case 'flaginappropriate':

        $tagid = tag_get_id($tag);

        tag_set_flag($tagid);

        redirect($CFG->wwwroot.'/tag/index.php?tag='. rawurlencode($tag), get_string('responsiblewillbenotified', 'tag'));
        break;

    default:
        print_error('unknowaction');
        break;
}
