<?php
/**
 * @component     Plugin Contact CG Add CC
 * @license https://www.gnu.org/licenses/gpl-3.0.html GNU/GPL
 * @copyright (C) 2025 ConseilGouz. All Rights Reserved.
 * @author ConseilGouz
**/

namespace Conseilgouz\Plugin\Contact\CGAddCC\Extension;

// No direct access.
defined('_JEXEC') or die();
use Joomla\CMS\Event\Contact\SubmitContactEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Mail\Exception\MailDisabledException;
use Joomla\CMS\Mail\MailTemplate;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Router\Route;
use Joomla\CMS\String\PunycodeHelper;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\UserFactoryAwareInterface;
use Joomla\CMS\User\UserFactoryAwareTrait;
use Joomla\Component\Fields\Administrator\Helper\FieldsHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\SubscriberInterface;
use PHPMailer\PHPMailer\Exception as phpMailerException;

final class Cgaddcc extends CMSPlugin implements SubscriberInterface, UserFactoryAwareInterface
{
    use UserFactoryAwareTrait;

    public function __construct($subject, $config)
    {
        parent::__construct($subject, $config);

    }
    /**
     * Returns an array of events this subscriber will listen to.
     *
     * @return  array
     *
     * @since   5.0.0
     */
    public static function getSubscribedEvents(): array
    {
        return ['onSubmitContact' => 'submitContact'
                ];
    }
    // Check IP on prepare Forms
    public function submitContact(SubmitContactEvent $event)
    {
        $app = Factory::getApplication();
        $stub   = $app->getInput()->getString('id');
        $contact = $event->getContact();
        $data = $event->getData();
        $type = $this->params->get('user', 'group');
        $contact->email_bcc = "";
        if ($type == 'group') {
            $usergroups = $this->params->get('usergroups', ['8']);
            $users = $this->getUsers(implode(',', $usergroups));
            foreach ($users as $email) {
                if ($contact->email_bcc) {
                    $contact->email_bcc .= ',';
                }
                $contact->email_bcc .= $email;
            }
        } else {
            $contact->email_bcc = $this->params->get('email', '');
        }
        $sent = $this->_sendEmail($data, $contact, false);
        $msg = '';
        if ($sent) {
            $msg = Text::_('COM_CONTACT_EMAIL_THANKS');
        }
        // Flush the data from the session
        $app->setUserState('com_contact.contact.data', null);

        // Redirect if it is set in the parameters, otherwise redirect back to where we came from
        if ($contact->params->get('redirect')) {
            $app->enqueueMessage($msg);
            $app->redirect($contact->params->get('redirect'));
        } else {
            $app->enqueueMessage($msg);
            $app->redirect(Route::_('index.php?option=com_contact&view=contact&id=' . $stub . '&catid=' . $contact->catid, false));
        }

        return true;
    }
    /**
      * Method to get a model object, loading it if required.
      *
      * @param   array      $data               The data to send in the email.
      * @param   \stdClass  $contact            The user information to send the email to
      * @param   boolean    $emailCopyToSender  True to send a copy of the email to the user.
      *
      * @return  boolean  True on success sending the email, false on failure.
      *
      * @since   1.6.4
      */
    private function _sendEmail($data, $contact, $emailCopyToSender)
    {
        $app = Factory::getApplication();

        if ($contact->email_to == '' && $contact->user_id != 0) {
            $contact_user      = $this->getUserFactory()->loadUserById($contact->user_id);
            $contact->email_to = $contact_user->email;
        }

        $templateData = [
            'sitename'     => $app->get('sitename'),
            'name'         => $data['contact_name'],
            'contactname'  => $contact->name,
            'email'        => PunycodeHelper::emailToPunycode($data['contact_email']),
            'subject'      => $data['contact_subject'],
            'body'         => stripslashes($data['contact_message']),
            'url'          => Uri::base(),
            'customfields' => '',
        ];

        // Load the custom fields
        if (!empty($data['com_fields']) && $fields = FieldsHelper::getFields('com_contact.mail', $contact, true, $data['com_fields'])) {
            $output = FieldsHelper::render(
                'com_contact.mail',
                'fields.render',
                [
                    'context' => 'com_contact.mail',
                    'item'    => $contact,
                    'fields'  => $fields,
                ]
            );

            if ($output) {
                $templateData['customfields'] = $output;
            }
        }

        try {
            $mailer = new MailTemplate('com_contact.mail', $app->getLanguage()->getTag());
            $mailer->addRecipient($contact->email_to);
            if ($contact->email_bcc) { // should contain something, otherwise why use this plugin....
                $mailer->addRecipient($contact->email_bcc, '', 'bcc');
            }
            $mailer->setReplyTo($templateData['email'], $templateData['name']);
            $mailer->addTemplateData($templateData);
            $mailer->addUnsafeTags(['name', 'email', 'body']);
            $sent = $mailer->send();

        } catch (MailDisabledException | phpMailerException $exception) {
            try {
                Log::add(Text::_($exception->getMessage()), Log::WARNING, 'jerror');

                $sent = false;
            } catch (\RuntimeException $exception) {
                $app->enqueueMessage(Text::_($exception->errorMessage()), 'warning');

                $sent = false;
            }
        }

        return $sent;
    }
    private static function getUsers($usergroups)
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->getQuery(true)
            ->select('DISTINCT '.$db->quoteName('u.email'))
            ->from($db->quoteName('#__users').' as u ')
            ->join('LEFT', $db->quoteName('#__user_usergroup_map').' as g on u.id = g.user_id')
            ->where($db->quoteName('block') . ' = 0 AND g.group_id IN ('.$usergroups.')');
        $db->setQuery($query);
        return (array) $db->loadColumn();
    }

}
