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
        if (strcmp("html", $docType) != 0) {
            return null;
        }

        if (strcmp($context, "com_content.article") != 0 or $this->isRestricted()) {
            return null;
        }

        $userId = JFactory::getUser()->get("id");
        if (!empty($userId)) {
            $this->givePoints($userId, JFactory::getUser()->get("name"), $article);
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

        switch ($this->app->input->getCmd("option")) {

            case "com_content":
                $restricted = $this->isRestrictedContent($this->app->input->getCmd("view"), $this->app->input->getCmd("task"));
                break;
        }

        return $restricted;
    }

    protected function isRestrictedContent($view, $task)
    {
        if (strcmp("article", $view) == 0) {
            return false;
        }

        return true;
    }

    /**
     * Add points to user account.
     *
     * @param int $userId
     * @param string $name User name
     * @param object $article
     */
    protected function givePoints($userId, $name, $article)
    {
        $pointsTypesValues = $this->params->get("points_types", 0);

        // Parse point types
        $pointsTypes = array();
        if (!empty($pointsTypesValues)) {
            $pointsTypes = json_decode($pointsTypesValues, true);
            Joomla\Utilities\ArrayHelper::toInteger($pointsTypes);
        }

        if (!empty($pointsTypes)) {

            $uri = JUri::getInstance();
            $domain = $uri->getScheme(). "://". $uri->getHost();

            foreach ($pointsTypes as $pointsType) {
                $pointsType["value"] = (int)$pointsType["value"];

                // If there are no points for giving, continue for next one.
                if (!$pointsType["value"]) {
                    continue;
                }

                $points = Gamification\Points\Points::getInstance(JFactory::getDbo(), $pointsType["id"]);

                if ($points->getId() and $points->isPublished()) {

                    $keys = array(
                        "hash" => md5($userId . ":" . $article->id . ":" . $pointsType["id"])
                    );

                    // Check for already given points.
                    if (!$this->params->get("enable_debug", 0)) {
                        $pointsHistory = new Gamification\Points\History(JFactory::getDbo());
                        if (!$pointsHistory->isExists($keys)) {
                            $pointsHistory->setUserId($userId);
                            $pointsHistory->setPointsId($pointsType["id"]);
                            $pointsHistory->setPoints($pointsType["value"]);
                            $pointsHistory->setHash(md5($userId . ":" . $article->id . ":" . $pointsType["id"]));
                            $pointsHistory->store();
                        } else {
                            continue;
                        }
                    }

                    $keys = array(
                        "user_id"   => $userId,
                        "points_id" => $points->getId()
                    );

                    $userPoints = new Gamification\User\Points(JFactory::getDbo());
                    $userPoints->load($keys);

                    // Create an record if it does not exists.
                    if (!$userPoints->getId()) {
                        $userPoints->startCollectingPoints($keys);
                    }

                    // Increase user points.
                    $options = array(
                        "context" => "com_content.article"
                    );

                    $userPoints->increase($pointsType["value"], $options);

                    // Send notification and store activity.

                    $params = JComponentHelper::getParams("com_gamification");

                    $options = array(
                        "social_platform" => "",
                        "user_id" => $userId,
                        "title" =>  $article->title,
                        "url"   =>  $domain.$article->readmore_link,
                        "app"   => "gamification.points"
                    );

                    // Store activity.
                    $activityService = $params->get("integration_activities");
                    if ($this->params->get("store_activity", 0) and !empty($activityService)) {

                        $options["social_platform"] = $activityService;

                        $points = htmlspecialchars($pointsType["value"] . " " . $userPoints->getTitle(), ENT_QUOTES, "UTF-8");
                        $notice = JText::sprintf("PLG_CONTENT_GAMIFICATIONPOINTS_ACTIVITY_READ_ARTICLE", $name, $points);

                        Gamification\Helper::storeActivity($notice, $options);
                    }

                    // Send notifications.
                    $notificationService = $params->get("integration_notifications");
                    if ($this->params->get("send_notification", 0) and !empty($notificationService)) {

                        $options["social_platform"] = $notificationService;

                        $points = htmlspecialchars($pointsType["value"] . " " . $userPoints->getTitle(), ENT_QUOTES, "UTF-8");
                        $message = JText::sprintf("PLG_CONTENT_GAMIFICATIONPOINTS_NOTIFICATION_READ_ARTICLE", $points, $article->title);

                        Gamification\Helper::sendNotification($message, $options);
                    }

                }
            }
        }
    }


}
