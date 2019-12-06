<?php

if (!defined('ABSPATH')) exit;

class PluginsWPTP extends WPTelegramPro
{
    protected $tabID = 'plugins-wptp-tab',
        $plugins = array('contact-form-7' => 'contact-form-7/wp-contact-form-7.php'),
        $currentActivePlugins = array(),
        $cf7_fields = array('email', 'name', 'subject', 'message'),
        $cf7_prefix_field = 'wptelegrampro_cf7_',
        $cf7_message_ids = array();
    public static $instance = null;

    public function __construct()
    {
        if (!$this->check_plugins()) return;
        parent::__construct(true);

        add_filter('wptelegrampro_settings_tabs', [$this, 'settings_tab'], 35);
        add_action('wptelegrampro_settings_content', [$this, 'settings_content']);

        if (in_array('contact-form-7', $this->currentActivePlugins) && $this->get_option('cf7_new_message_notification', false)) {
            add_action('wpcf7_submit', [$this, 'wpcf7_submit'], 5, 2);
            add_action('wpcf7_after_flamingo', [$this, 'wpcf7_after_flamingo']);
        }
    }

    function settings_tab($tabs)
    {
        $tabs[$this->tabID] = __('Plugins', $this->plugin_key);
        return $tabs;
    }

    function settings_content()
    {
        $this->options = get_option($this->plugin_key);
        ?>
        <div id="<?php echo $this->tabID ?>-content" class="wptp-tab-content hidden">
            <table>
                <?php if (in_array('contact-form-7', $this->currentActivePlugins)) {
                    ?>
                    <tr>
                        <th colspan="2"><?php _e('Contact Form 7', $this->plugin_key) ?></th>
                    </tr>
                    <tr>
                        <td>
                            <label for="cf7_new_message_notification"><?php _e('Notification for new message', $this->plugin_key) ?></label>
                        </td>
                        <td>
                            <label><input type="checkbox" value="1" id="cf7_new_message_notification"
                                          name="cf7_new_message_notification" <?php checked($this->get_option('cf7_new_message_notification', 0), 1) ?>> <?php _e('Active', $this->plugin_key) ?>
                            </label>
                            <br><br>
                            <span class="description">
                            <?php
                            echo sprintf(__('Open the <a href="%s" target="_blank">Additional Settings</a> tab in the contact form editor page, and add lines like these:', $this->plugin_key), 'https://contactform7.com/admin-screen/#additional-settings');
                            ?>
                        </span>
                            <br>
                            <textarea cols="30" rows="4" onfocus="this.select();" onmouseup="return false;"
                                      style="resize:none; overflow:hidden" readonly><?php
                                foreach ($this->cf7_fields as $field)
                                    echo $this->cf7_prefix_field . $field . ': "[your-' . $field . ']"' . "\n";
                                ?></textarea>
                        </td>
                    </tr>
                <?php } ?>
            </table>
        </div>
        <?php
    }

    function wpcf7_submit($contact_form, $result)
    {
        if ($contact_form->in_demo_mode())
            return;

        $submission = WPCF7_Submission::get_instance();

        if (!$submission
            or !$posted_data = $submission->get_posted_data()) {
            return;
        }

        if ($submission->get_meta('do_not_store'))
            return;

        $fields_senseless =
            $contact_form->scan_form_tags(array('feature' => 'do-not-store'));

        $exclude_names = array();

        foreach ($fields_senseless as $tag) {
            $exclude_names[] = $tag['name'];
        }

        $exclude_names[] = 'g-recaptcha-response';

        foreach ($posted_data as $key => $value) {
            if ('_' == substr($key, 0, 1)
                or in_array($key, $exclude_names)) {
                unset($posted_data[$key]);
            }
        }

        $email = $this->wpcf7_get_value('email', $contact_form);
        $name = $this->wpcf7_get_value('name', $contact_form);
        $subject = $this->wpcf7_get_value('subject', $contact_form);
        $message = $this->wpcf7_get_value('message', $contact_form);

        $meta = array();

        $special_mail_tags = array('serial_number', 'remote_ip',
            'user_agent', 'url', 'date', 'time', 'post_id', 'post_name',
            'post_title', 'post_url', 'post_author', 'post_author_email',
            'site_title', 'site_description', 'site_url', 'site_admin_email',
            'user_login', 'user_email', 'user_display_name');

        foreach ($special_mail_tags as $smt) {
            $meta[$smt] = apply_filters('wpcf7_special_mail_tags', '',
                sprintf('_%s', $smt), false);
        }

        $akismet = isset($submission->akismet)
            ? (array)$submission->akismet : null;

        $args = array(
            'subject' => $subject,
            'from' => trim(sprintf('%s <%s>', $name, $email)),
            'from_name' => $name,
            'from_email' => $email,
            'message' => $message,
            'fields' => $posted_data,
            'meta' => $meta,
            'akismet' => $akismet,
            'spam' => ('spam' == $result['status']),
            'consent' => $submission->collect_consent(),
        );

        if ('mail_sent' == $result['status']) {
            $users = $this->get_users();
            if ($users) {
                $keyboards = null;
                $text = "*" . __('New message', $this->plugin_key) . "*\n\n";
                if ($email)
                    $text .= __('Email', $this->plugin_key) . ': ' . $email . "\n";
                if ($name)
                    $text .= __('Name', $this->plugin_key) . ': ' . $name . "\n";
                if ($subject)
                    $text .= __('Subject', $this->plugin_key) . ': ' . $subject . "\n";
                if ($message)
                    $text .= __('Message', $this->plugin_key) . ':' . "\n" . $message . "\n";

                $text .= __('Date', $this->plugin_key) . ': ' . HelpersWPTP::localeDate() . "\n";

                $text = apply_filters('wptelegrampro_wpcf7_new_message_notification_text', $text, $args, $contact_form, $result);

                if ($text)
                    foreach ($users as $user) {
                        $this->telegram->sendMessage($text, $keyboards, $user['user_id'], 'Markdown');
                        $this->cf7_message_ids[$user['user_id']] = $this->telegram->get_last_result()['result']['message_id'];
                    }
            }
        }
    }

    function wpcf7_after_flamingo($result)
    {
        if (count($this->cf7_message_ids) && isset($result['flamingo_inbound_id']) && $result['flamingo_inbound_id']) {
            $keyboard = array(array(
                array(
                    'text' => '👁️',
                    'url' => admin_url('admin.php?page=flamingo_inbound&post=' . $result['flamingo_inbound_id'] . '&action=edit')
                ),
                array(
                    'text' => '📂',
                    'url' => admin_url('admin.php?page=flamingo_inbound')
                )
            ));
            $keyboards = $this->telegram->keyboard($keyboard, 'inline_keyboard');
            foreach ($this->cf7_message_ids as $user_id => $message_id)
                $this->telegram->editMessageReplyMarkup($keyboards, $message_id, $user_id);
        }
    }

    function wpcf7_get_value($field, $contact_form)
    {
        if (empty($field)
            or empty($contact_form)) {
            return false;
        }

        $value = '';

        if (in_array($field, $this->cf7_fields)) {
            $templates = $contact_form->additional_setting($this->cf7_prefix_field . $field);

            if (empty($templates[0])) {
                $template = sprintf('[your-%s]', $field);
            } else {
                $template = trim(wpcf7_strip_quote($templates[0]));
            }

            $value = wpcf7_mail_replace_tags($template);
        }

        $value = apply_filters('wptelegrampro_wpcf7_get_value', $value,
            $field, $contact_form);

        return $value;
    }

    function check_plugins()
    {
        foreach ($this->plugins as $plugin => $path)
            if ($this->check_plugin_active($path))
                $this->currentActivePlugins[] = $plugin;
        return count($this->currentActivePlugins) > 0;
    }

    /**
     * Returns an instance of class
     * @return PluginsWPTP
     */
    static function getInstance()
    {
        if (self::$instance == null)
            self::$instance = new PluginsWPTP();
        return self::$instance;
    }
}

$PluginsWPTP = PluginsWPTP::getInstance();