<?php
/**
 * @package      Gamification
 * @subpackage   Plugins
 * @author       Todor Iliev
 * @copyright    Copyright (C) 2015 Todor Iliev <todor@itprism.com>. All rights reserved.
 * @license      GNU General Public License version 3 or later; see LICENSE.txt
 */

// no direct access
defined('_JEXEC') or die;

jimport('Prism.init');
jimport('Gamification.init');

/**
 * Gamification Points Plugin
 *
 * @package      Gamification
 * @subpackage   Plugins
 */
class plgContentGamificationPoints extends JPlugin
{
    /**
     * @var Joomla\Registry\Registry
     */
    public $params;

    /**
     * @var JApplicationSite
     */
    public $app;

    /**
     * Affects constructor behavior. If true, language files will be loaded automatically.
     *
     * @var    boolean
     * @since  3.1
     */
    protected $autoloadLanguage = true;

    public function onContentAfterDisplay($context, &$article, &$params)
    {
        if ($this->app->isAdmin()) {
            return null;
        }

        $doc = JFactory::getDocument();
        /**  @var $doc JDocumentHtml */

        // Check document type
        $docType = $doc->getType();
        if (strcmp('html', $docType) !== 0) {
            return null;
        }

        if (strcmp($context, 'com_content.article') !== 0 or $this->isRestricted()) {
            return null;
        }

        $user   = JFactory::getUser();
        $userId = $user->get('id');
        if ($userId > 0) {
            $this->givePoints($userId, $user->get('name'), $article);
        }
    }

    /**
     *  Check for restricted components, views and task.
     *
     * @return bool
     */
    protected function isRestricted()
    {
        $restricted = true;

        switch ($this->app->input->getCmd('option')) {
            case 'com_content':
                $restricted = $this->isRestrictedContent($this->app->input->getCmd('view'), $this->app->input->getCmd('task'));
                break;
        }

        return $restricted;
    }

    protected function isRestrictedContent($view, $task)
    {
        return !(strcmp('article', $view) === 0);
    }

    /**
     * Add points to user account.
     *
     * @param int $userId
     * @param string $name User name
     * @param stdClass $article
     *
     * @throws \RuntimeException
     * @throws \InvalidArgumentException
     * @throws \UnexpectedValueException
     * @throws \OutOfBoundsException
     */
    protected function givePoints($userId, $name, &$article)
    {
        $pointsTypesValues = $this->params->get('points_types', 0);

        // Parse point types
        $pointsTypes = array();
        if (is_string($pointsTypesValues) and $pointsTypesValues !== '') {
            $pointsTypes = json_decode($pointsTypesValues, true);
            Joomla\Utilities\ArrayHelper::toInteger($pointsTypes);
        }

        if (count($pointsTypes) > 0) {
            $uri    = JUri::getInstance();
            $domain = $uri->getScheme(). '://'. $uri->getHost();

            $params = JComponentHelper::getParams('com_gamification');

            $activityService = $params->get('integration_activities');
            $activityStates  = $this->params->get('store_activity');
            $activityStates  = Joomla\Utilities\ArrayHelper::toInteger($activityStates);

            $notificationService = $params->get('integration_notifications');
            $notificationStates  = $this->params->get('send_notification');
            $notificationStates  = Joomla\Utilities\ArrayHelper::toInteger($notificationStates);

            $integrationOptions = new \Joomla\Registry\Registry(array(
                'platform' => '',
                'user_id'  =>  $userId,
                'title'    =>  $article->title,
                'url'      =>  $domain.$article->readmore_link,
                'app'      => 'gamification.points'
            ));

            foreach ($pointsTypes as $pointsType) {
                $pointsType['value'] = (int)$pointsType['value'];

                // If there are no points for giving, continue for next one.
                if (!$pointsType['value']) {
                    continue;
                }

                $keys = array(
                    'user_id'   => $userId,
                    'points_id' => $pointsType['id']
                );

                $container    = Prism\Container::getContainer();
                $containerKey = Prism\Utilities\StringHelper::generateMd5Hash(Gamification\Points\Points::class, $pointsType['id']);

                // Get basic points object.
                if (!$container->exists($containerKey)) {
                    $points     = new Gamification\Points\Points(JFactory::getDbo());
                    $points->load($pointsType['id']);

                    $container->set($containerKey, $points);
                } else {
                    $points = $container->get($containerKey);
                }

                if ($points->getId() and $points->isPublished()) {
                    // Get user points object.
                    $userPoints = new Gamification\User\Points\Points(JFactory::getDbo());
                    $userPoints->setContainer($container);
                    $userPoints->load($keys);

                    // Create a record if it does not exist.
                    if (!$userPoints->getId()) {
                        $userPoints->startCollectingPoints($keys);
                    }

                    $context = 'com_content.read.article';
                    
                    $hash = md5($userId . ':' . $article->id . ':' . $pointsType['id']);
                    $keys = array('hash' => $hash);

                    // Check for has already given points.
                    if (!$this->isDebugging()) {
                        $pointsHistory = new Gamification\Points\History(JFactory::getDbo());
                        if (!$pointsHistory->isExists($keys)) {
                            $pointsHistory->setUserId($userId);
                            $pointsHistory->setPointsId($pointsType['id']);
                            $pointsHistory->setPoints($pointsType['value']);
                            $pointsHistory->setContext($context);
                            $pointsHistory->setHash($hash);
                            $pointsHistory->store();
                        } else {
                            continue;
                        }
                    }

                    // Increase user points.
                    
                    $userPointsManager = new Gamification\User\Points\PointsManager(JFactory::getDbo());
                    $userPointsManager->setPoints($userPoints);
                    
                    $userPointsManager->increase($context, $pointsType['value']);

                    // Send notification and store activity.

                    // Store activity.
                    if (in_array((int)$pointsType['id'], $activityStates, true) and $activityService !== '') {
                        $integrationOptions->set('platform', $activityService);

                        $points = htmlspecialchars($pointsType['value'] . ' ' . $userPoints->getPoints()->getTitle(), ENT_QUOTES, 'UTF-8');
                        $notice = JText::sprintf('PLG_CONTENT_GAMIFICATIONPOINTS_ACTIVITY_READ_ARTICLE', $name, $points);

                        Gamification\Helper::storeActivity($notice, $integrationOptions);
                    }

                    // Send notifications.
                    if (in_array((int)$pointsType['id'], $notificationStates, true) and $notificationService !== '') {
                        $integrationOptions->set('platform', $notificationService);

                        $points = htmlspecialchars($pointsType['value'] . ' ' . $userPoints->getPoints()->getTitle(), ENT_QUOTES, 'UTF-8');
                        $message = JText::sprintf('PLG_CONTENT_GAMIFICATIONPOINTS_NOTIFICATION_READ_ARTICLE', $points, $article->title);

                        Gamification\Helper::sendNotification($message, $integrationOptions);
                    }
                }
            }
        }
    }

    /**
     * Check if debug mode is enabled and it is allowed to debug the system by current user.
     *
     * @return bool
     */
    protected function isDebugging()
    {
        if ($this->params->get('enable_debug', 0)) {
            $u = JFactory::getUser();

            $filterGroups = (array)$this->params->get('filter_groups', array());
            $userGroups   = $u->getAuthorisedGroups();

            $result = array_intersect($filterGroups, $userGroups);

            return (bool)(count($result) > 0);
        }

        return false;
    }
}
