<?php

/**
 * Shared code for attachments handling in Kolab plugins
 *
 * @version @package_version@
 * @author Thomas Bruederli <bruederli@kolabsys.com>
 * @author Aleksander Machniak <machniak@kolabsys.com>
 *
 * Copyright (C) 2012-2018, Kolab Systems AG <contact@kolabsys.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

class kolab_attachments_handler
{
    private $rc;
    private $attachment;

    public function __construct()
    {
        $this->rc = rcmail::get_instance();
    }

    public static function ui()
    {
        $rcmail = rcmail::get_instance();
        $self   = new self();

        $rcmail->output->add_handler('plugin.attachments_form', [$self, 'files_form']);
        $rcmail->output->add_handler('plugin.attachments_list', [$self, 'files_list']);
        $rcmail->output->add_handler('plugin.filedroparea', [$self, 'files_drop_area']);
    }

    /**
     * Generate HTML element for attachments list
     */
    public function files_list($attrib = [])
    {
        if (empty($attrib['id'])) {
            $attrib['id'] = 'kolabattachmentlist';
        }

        // define list of file types which can be displayed inline
        // same as in program/steps/mail/show.inc
        $this->rc->output->set_env('mimetypes', (array)$this->rc->config->get('client_mimetypes'));

        $this->rc->output->add_gui_object('attachmentlist', $attrib['id']);

        return html::tag('ul', $attrib, '', html::$common_attrib);
    }

    /**
     * Generate the form for event attachments upload
     */
    public function files_form($attrib = [])
    {
        // add ID if not given
        if (empty($attrib['id'])) {
            $attrib['id'] = 'kolabuploadform';
        }

        return $this->rc->upload_form($attrib, 'uploadform', 'upload-file', ['multiple' => true]);
    }

    /**
     * Register UI object for HTML5 drag & drop file upload
     */
    public function files_drop_area($attrib = [])
    {
        // add ID if not given
        if (empty($attrib['id'])) {
            $attrib['id'] = 'kolabfiledroparea';
        }

        $this->rc->output->add_gui_object('filedrop', $attrib['id']);
        $this->rc->output->set_env('filedrop', ['action' => 'upload', 'fieldname' => '_attachments']);
    }

    /**
     * Displays attachment preview page
     */
    public function attachment_page($attachment)
    {
        $this->attachment = $attachment;

        $this->rc->plugins->include_script('libkolab/libkolab.js');

        $this->rc->output->add_handler('plugin.attachmentframe', [$this, 'attachment_frame']);
        $this->rc->output->add_handler('plugin.attachmentcontrols', [$this, 'attachment_header']);
        $this->rc->output->set_env('filename', $attachment['name']);
        $this->rc->output->set_env('mimetype', $attachment['mimetype']);
        $this->rc->output->send('libkolab.attachment');
    }

    /**
     * Handler for attachment uploads
     */
    public function attachment_upload($session_key, $id_prefix = '')
    {
        // Upload progress update
        if (!empty($_GET['_progress'])) {
            $this->rc->upload_progress();
        }

        $uploads_api = method_exists($this->rc, 'insert_uploaded_file'); // Roundcube >= 1.7

        $id       = rcube_utils::get_input_value('_id', rcube_utils::INPUT_GPC);
        $uploadid = rcube_utils::get_input_value('_uploadid', rcube_utils::INPUT_GPC);
        $recid    = $uploads_api ? $session_key : ($id_prefix . ($id ?: 'new'));

        if (!$uploads_api) {
            if (empty($_SESSION[$session_key]) || $_SESSION[$session_key]['id'] != $recid) {
                $_SESSION[$session_key] = [];
                $_SESSION[$session_key]['id'] = $recid;
                $_SESSION[$session_key]['attachments'] = [];
            }
        }

        // clear all stored output properties (like scripts and env vars)
        $this->rc->output->reset();

        if (is_array($_FILES['_attachments']['tmp_name'])) {
            foreach ($_FILES['_attachments']['tmp_name'] as $i => $filepath) {
                // Process uploaded attachment if there is no error
                $err = $_FILES['_attachments']['error'][$i];

                if (!$err) {
                    $filename   = $_FILES['_attachments']['name'][$i];
                    $attachment = [
                        'path'     => $filepath,
                        'size'     => $_FILES['_attachments']['size'][$i],
                        'name'     => $filename,
                        'mimetype' => rcube_mime::file_content_type($filepath, $filename, $_FILES['_attachments']['type'][$i]),
                        'group'    => $recid,
                    ];

                    if ($uploads_api) {
                        $err = !$this->rc->insert_uploaded_file($attachment);
                    } else {
                        $attachment = $this->rc->plugins->exec_hook('attachment_upload', $attachment);
                    }
                }

                if (!$err && !empty($attachment['status']) && empty($attachment['abort'])) {
                    $id = $attachment['id'];

                    unset($attachment['status'], $attachment['abort']);

                    if (!$uploads_api) {
                        // store new attachment in session
                        $this->rc->session->append($session_key . '.attachments', $id, $attachment);
                    }

                    if (!empty($_SESSION[$session_key . '_deleteicon'])
                        && is_file($icon = $_SESSION[$session_key . '_deleteicon'])
                    ) {
                        $button = html::img([
                            'src' => $icon,
                            'alt' => $this->rc->gettext('delete'),
                        ]);
                    } elseif (!empty($_SESSION[$session_key . '_textbuttons'])) {
                        $button = rcube::Q($this->rc->gettext('delete'));
                    } else {
                        $button = '';
                    }

                    $link_content = sprintf(
                        '<span class="attachment-name">%s</span><span class="attachment-size">(%s)</span>',
                        rcube::Q($attachment['name']),
                        $this->rc->show_bytes($attachment['size'])
                    );

                    $delete_link = html::a([
                            'href'       => "#delete",
                            'class'      => 'delete',
                            'onclick'    => sprintf("return %s.remove_from_attachment_list('rcmfile%s')", rcmail_output::JS_OBJECT_NAME, $id),
                            'title'      => $this->rc->gettext('delete'),
                            'aria-label' => $this->rc->gettext('delete') . ' ' . $attachment['name'],
                        ], $button);

                    $content_link = html::a([
                            'href'    => "#load",
                            'class'   => 'filename',
                            'onclick' => 'return false', // sprintf("return %s.command('load-attachment','rcmfile%s', this, event)", rcmail_output::JS_OBJECT_NAME, $id),
                         ], $link_content);

                    $left = !empty($_SESSION[$session_key . '_icon_pos']) && $_SESSION[$session_key . '_icon_pos'] == 'left';
                    $content = $left ? $delete_link . $content_link : $content_link . $delete_link;

                    $this->rc->output->command('add2attachment_list', "rcmfile$id", [
                            'html'      => $content,
                            'name'      => $attachment['name'],
                            'mimetype'  => $attachment['mimetype'],
                            'classname' => 'no-menu ' . rcube_utils::file2class($attachment['mimetype'], $attachment['name']),
                            'complete'  => true,
                        ], $uploadid);
                } else {  // upload failed
                    if ($err == UPLOAD_ERR_INI_SIZE || $err == UPLOAD_ERR_FORM_SIZE) {
                        $msg = $this->rc->gettext(['name' => 'filesizeerror', 'vars' => [
                            'size' => $this->rc->show_bytes(parse_bytes(ini_get('upload_max_filesize')))]]);
                    } elseif (!empty($attachment['error'])) {
                        $msg = $attachment['error'];
                    } else {
                        $msg = $this->rc->gettext('fileuploaderror');
                    }

                    $this->rc->output->command('display_message', $msg, 'error');
                    $this->rc->output->command('remove_from_attachment_list', $uploadid);
                }
            }
        } elseif ($_SERVER['REQUEST_METHOD'] == 'POST') {
            // if filesize exceeds post_max_size then $_FILES array is empty,
            // show filesizeerror instead of fileuploaderror
            if ($maxsize = ini_get('post_max_size')) {
                $msg = $this->rc->gettext(['name' => 'filesizeerror', 'vars' => [
                    'size' => $this->rc->show_bytes(parse_bytes($maxsize))]]);
            } else {
                $msg = $this->rc->gettext('fileuploaderror');
            }

            $this->rc->output->command('display_message', $msg, 'error');
            $this->rc->output->command('remove_from_attachment_list', $uploadid);
        }

        $this->rc->output->send('iframe');
    }


    /**
     * Deliver an event/task attachment to the client
     * (similar as in Roundcube core program/steps/mail/get.inc)
     */
    public function attachment_get($attachment)
    {
        ob_end_clean();

        if ($attachment && !empty($attachment['body'])) {
            // allow post-processing of the attachment body
            $part = new rcube_message_part();
            $part->filename  = $attachment['name'];
            $part->size      = $attachment['size'];
            $part->mimetype  = $attachment['mimetype'];

            $plugin = $this->rc->plugins->exec_hook('message_part_get', [
                'body'     => $attachment['body'],
                'mimetype' => strtolower($attachment['mimetype']),
                'download' => !empty($_GET['_download']),
                'part'     => $part,
            ]);

            if (!empty($plugin['abort'])) {
                exit;
            }

            $mimetype = $plugin['mimetype'];
            [$ctype_primary, $ctype_secondary] = explode('/', $mimetype);

            $browser = $this->rc->output->browser;

            // send download headers
            if ($plugin['download']) {
                header("Content-Type: application/octet-stream");
                if ($browser->ie) {
                    header("Content-Type: application/force-download");
                }
            } elseif ($ctype_primary == 'text') {
                header("Content-Type: text/$ctype_secondary");
            } else {
                header("Content-Type: $mimetype");
                header("Content-Transfer-Encoding: binary");
            }

            // display page, @TODO: support text/plain (and maybe some other text formats)
            if ($mimetype == 'text/html' && empty($_GET['_download'])) {
                $OUTPUT = new rcmail_html_page();
                // @TODO: use washtml on $body
                $OUTPUT->write($plugin['body']);
            } else {
                // don't kill the connection if download takes more than 30 sec.
                @set_time_limit(0);

                $filename = $attachment['name'];
                $filename = preg_replace('[\r\n]', '', $filename);

                if ($browser->ie) {
                    $filename = rawurlencode($filename);
                } else {
                    $filename = addcslashes($filename, '"');
                }

                $disposition = !empty($_GET['_download']) ? 'attachment' : 'inline';
                header("Content-Disposition: $disposition; filename=\"$filename\"");

                echo $plugin['body'];
            }

            exit;
        }

        // if we arrive here, the requested part was not found
        header('HTTP/1.1 404 Not Found');
        exit;
    }

    /**
     * Show "loading..." page in attachment iframe
     */
    public function attachment_loading_page()
    {
        $url = str_replace('&_preload=1', '', $_SERVER['REQUEST_URI']);
        $message = $this->rc->gettext('loadingdata');

        header('Content-Type: text/html; charset=' . RCUBE_CHARSET);
        print "<html>\n<head>\n"
            . '<meta http-equiv="refresh" content="0; url=' . rcube::Q($url) . '">' . "\n"
            . '<meta http-equiv="content-type" content="text/html; charset=' . RCUBE_CHARSET . '">' . "\n"
            . "</head>\n<body>\n$message\n</body>\n</html>";
        exit;
    }

    /**
     * Template object for attachment display frame
     */
    public function attachment_frame($attrib = [])
    {
        $mimetype = strtolower($this->attachment['mimetype']);
        [$ctype_primary, $ctype_secondary] = explode('/', $mimetype);

        $attrib['src'] = './?' . str_replace('_frame=', ($ctype_primary == 'text' ? '_show=' : '_preload='), $_SERVER['QUERY_STRING']);

        $this->rc->output->add_gui_object('attachmentframe', $attrib['id']);

        return html::iframe($attrib);
    }

    /**
     * Template object for attachment metadata
     */
    public function attachment_header($attrib = [])
    {
        $rcmail  = rcube::get_instance();
        $dl_link = strtolower($attrib['downloadlink'] ?? '') == 'true';
        $dl_url  = $this->rc->url(['_frame' => null, '_download' => 1] + $_GET);
        $table   = new html_table(['cols' => $dl_link ? 3 : 2]);

        if (!empty($this->attachment['name'])) {
            $table->add('title', rcube::Q($this->rc->gettext('filename')));
            $table->add('header', rcube::Q($this->attachment['name']));

            if ($dl_link) {
                $table->add('download-link', html::a($dl_url, rcube::Q($this->rc->gettext('download'))));
            }
        }

        if (!empty($this->attachment['mimetype'])) {
            $table->add('title', rcube::Q($this->rc->gettext('type')));
            $table->add('header', rcube::Q($this->attachment['mimetype']));
        }

        if (!empty($this->attachment['size'])) {
            $table->add('title', rcube::Q($this->rc->gettext('filesize')));
            $table->add('header', rcube::Q($this->rc->show_bytes($this->attachment['size'])));
        }

        $this->rc->output->set_env('attachment_download_url', $dl_url);

        return $table->show($attrib);
    }

    /**
     * Remove uploaded attachments
     */
    public function attachments_cleanup($session_key)
    {
        // Roundcube >= 1.7
        if (method_exists($this->rc, 'delete_uploaded_files')) {
            $this->rc->delete_uploaded_files($session_key);
        } elseif (!empty($_SESSION[$session_key]) && ($group = $_SESSION[$session_key]['id'])) {
            $this->rc->plugins->exec_hook('attachments_cleanup', ['group' => $group]);
            $this->rc->session->remove($session_key);
        }
    }

    /**
     * Collect uploaded attachments
     */
    public function attachments_set($session_key, $group, $ids = [])
    {
        $attachments = [];

        // Roundcube >= 1.7
        if (method_exists($this->rc, 'list_uploaded_files')) {
            foreach ($this->rc->list_uploaded_files($session_key) as $attachment) {
                if (is_array($ids) && in_array($attachment['id'], $ids)) {
                    $attachments[$attachment['id']] = $attachment;
                }
            }
        } elseif (!empty($_SESSION[$session_key]) && $_SESSION[$session_key]['id'] == $group) {
            if (!empty($_SESSION[$session_key]['attachments'])) {
                foreach ($_SESSION[$session_key]['attachments'] as $id => $attachment) {
                    if (is_array($ids) && in_array($id, $ids)) {
                        $attachments[$id] = $attachment;
                    }
                }
            }
        }

        foreach ($attachments as $id => $attachment) {
            $attachments[$id] = $this->rc->plugins->exec_hook('attachment_get', $attachment);
            unset($attachments[$id]['abort'], $attachments[$id]['group']);
        }

        return $attachments;
    }

    /**
     * Register mail message attachments
     */
    public function copy_mail_attachments($session_key, $group, $message)
    {
        $result = [];
        $imap   = $this->rc->get_storage();
        $uploads_api = method_exists($this->rc, 'insert_uploaded_file'); // Roundcube >= 1.7

        if (!$uploads_api) {
            if (!empty($_SESSION[$session_key]) || $_SESSION[$session_key]['id'] != $group) {
                $_SESSION[$session_key] = [
                    'id'          => $group,
                    'attachments' => [],
                ];
            }
        }

        foreach ((array) $message->attachments as $part) {
            $attachment = [
                'data'     => $imap->get_message_part($message->uid, $part->mime_id, $part),
                'size'     => $part->size,
                'name'     => $part->filename,
                'mimetype' => $part->mimetype,
                'group'    => $group,
            ];

            if ($uploads_api) {
                $attachment['status'] = $this->rc->insert_uploaded_file($attachment, 'attachment_save');
            } else {
                $attachment = $this->rc->plugins->exec_hook('attachment_save', $attachment);
            }

            if (!empty($attachment['status']) && empty($attachment['abort'])) {
                $id = $attachment['id'];
                $attachment['classname'] = rcube_utils::file2class($attachment['mimetype'], $attachment['name']);

                unset($attachment['status'], $attachment['abort'], $attachment['data']);
                if (!$uploads_api) {
                    // store new attachment in session
                    $_SESSION[$session_key]['attachments'][$id] = $attachment;
                }

                $attachment['id'] = 'rcmfile' . $attachment['id'];  // add prefix to consider it 'new'

                $result[] = $attachment;
            }
        }

        return $result;
    }
}
