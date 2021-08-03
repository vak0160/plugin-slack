<?php

namespace Kanboard\Plugin\Slack\Notification;

use Kanboard\Core\Base;
use Kanboard\Core\Notification\NotificationInterface;
use Kanboard\Model\TaskModel;
use Kanboard\Model\SubtaskModel;
use Kanboard\Model\CommentModel;
use Kanboard\Model\TaskFileModel;

/**
 * Slack Notification
 *
 * @package  notification
 * @author   Frederic Guillot
 */
class Slack extends Base implements NotificationInterface
{
    /**
     * Send notification to a user
     *
     * @access public
     * @param  array     $user
     * @param  string    $eventName
     * @param  array     $eventData
     */
    public function notifyUser(array $user, $eventName, array $eventData)
    {
        $webhook = $this->userMetadataModel->get($user['id'], 'slack_webhook_url', $this->configModel->get('slack_webhook_url'));
        $channel = $this->userMetadataModel->get($user['id'], 'slack_webhook_channel');

        if (! empty($webhook)) {
            if ($eventName === TaskModel::EVENT_OVERDUE) {
                foreach ($eventData['tasks'] as $task) {
                    $project = $this->projectModel->getById($task['project_id']);
                    $eventData['task'] = $task;
                    $this->sendMessage($webhook, $channel, $project, $eventName, $eventData);
                }
            } else {
                $project = $this->projectModel->getById($eventData['task']['project_id']);
                $this->sendMessage($webhook, $channel, $project, $eventName, $eventData);
            }
        }
    }

    /**
     * Send notification to a project
     *
     * @access public
     * @param  array     $project
     * @param  string    $eventName
     * @param  array     $eventData
     */
    public function notifyProject(array $project, $eventName, array $eventData)
    {
        $webhook = $this->projectMetadataModel->get($project['id'], 'slack_webhook_url', $this->configModel->get('slack_webhook_url'));
        $channel = $this->projectMetadataModel->get($project['id'], 'slack_webhook_channel');

        if (! empty($webhook)) {
            $this->sendMessage($webhook, $channel, $project, $eventName, $eventData);
        }
    }

    /**
     * Get message to send
     *
     * @access public
     * @param  array     $project
     * @param  string    $eventName
     * @param  array     $eventData
     * @return array
     */
    public function getMessage(array $project, $eventName, array $eventData)
    {
        // Get required data
        
        if ($this->userSession->isLogged()) 
        {
            $author = "**".$this->helper->user->getFullname()."**";
            $title = $this->notificationModel->getTitleWithAuthor($author, $eventName, $eventData);
        }
        else 
        {
            $title = $this->notificationModel->getTitleWithoutAuthor($eventName, $eventData);
        }
        
        $proj_name = isset($eventData['project_name']) ? $eventData['project_name'] : $eventData['task']['project_name'];
        $task_title = $eventData['task']['title'];
        $task_url = $this->helper->url->to('TaskViewController', 'show', array('task_id' => $eventData['task']['id'], 'project_id' => $project['id']), '', true);
        
        $attachment = '';
        
        // Build message
        
        $message = "**[".htmlspecialchars($proj_name, ENT_NOQUOTES | ENT_IGNORE)."]**\n";
        $message .= htmlspecialchars($title, ENT_NOQUOTES | ENT_IGNORE)."\n";
        
        if ($this->configModel->get('application_url') !== '') 
        {
            $message .= '📝 <'.$task_url.'|'.htmlspecialchars($task_title, ENT_NOQUOTES | ENT_IGNORE).'>';
        }
        else
        {
            $message .= htmlspecialchars($task_title, ENT_NOQUOTES | ENT_IGNORE);
        }
        
        // Add additional informations
        
        $description_events = array(TaskModel::EVENT_CREATE, TaskModel::EVENT_UPDATE, TaskModel::EVENT_USER_MENTION);
        $subtask_events = array(SubtaskModel::EVENT_CREATE, SubtaskModel::EVENT_UPDATE, SubtaskModel::EVENT_DELETE);
        $comment_events = array(CommentModel::EVENT_UPDATE, CommentModel::EVENT_CREATE, CommentModel::EVENT_DELETE, CommentModel::EVENT_USER_MENTION);
        
        if (in_array($eventName, $subtask_events))  // For subtask events
        {
            $subtask_status = $eventData['subtask']['status'];
            $subtask_symbol = '';
            
            if ($subtask_status == SubtaskModel::STATUS_DONE)
            {
                $subtask_symbol = '❌ ';
            }
            elseif ($subtask_status == SubtaskModel::STATUS_TODO)
            {
                $subtask_symbol = '';
            }
            elseif ($subtask_status == SubtaskModel::STATUS_INPROGRESS)
            {
                $subtask_symbol = '🕘 ';
            }
            
            $message .= "\n**  ↳ ".$subtask_symbol.'** *"'.htmlspecialchars($eventData['subtask']['title'], ENT_NOQUOTES | ENT_IGNORE).'"*';
        }
        
        elseif (in_array($eventName, $description_events))  // If description available
        {
            if ($eventData['task']['description'] != '')
            {
                $message .= "\n✏️ ".'*"'.htmlspecialchars($eventData['task']['description'], ENT_NOQUOTES | ENT_IGNORE).'"*';
            }
        }
        
        elseif (in_array($eventName, $comment_events))  // If comment available
        {
            $message .= "\n💬 ".'*"'.htmlspecialchars($eventData['comment']['comment'], ENT_NOQUOTES | ENT_IGNORE).'"*';
        }
        
        elseif ($eventName === TaskFileModel::EVENT_CREATE and $forward_attachments)  // If attachment available
        {
            $file_path = getcwd()."/data/files/".$eventData['file']['path'];
            $file_name = $eventData['file']['name'];
            $is_image = $eventData['file']['is_image'];
            
            mkdir(sys_get_temp_dir()."/kanboard_telegram_plugin");
            $attachment = sys_get_temp_dir()."/kanboard_telegram_plugin/".clean($file_name);
            file_put_contents($attachment, file_get_contents($file_path));
        }

        return array(
            'text' => $message,
            'username' => 'Kanboard',
            'icon_url' => 'https://raw.githubusercontent.com/kanboard/kanboard/master/assets/img/favicon.png',
            'attachments' => $attachment
        );
    }

    /**
     * Send message to Slack
     *
     * @access protected
     * @param  string    $webhook
     * @param  string    $channel
     * @param  array     $project
     * @param  string    $eventName
     * @param  array     $eventData
     */
    protected function sendMessage($webhook, $channel, array $project, $eventName, array $eventData)
    {
        $payload = $this->getMessage($project, $eventName, $eventData);

        if (! empty($channel)) {
            $payload['channel'] = $channel;
        }

        $this->httpClient->postJsonAsync($webhook, $payload);
    }
}
