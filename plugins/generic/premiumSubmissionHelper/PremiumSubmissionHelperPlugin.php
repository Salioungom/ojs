<?php

declare(strict_types=1);

namespace APP\plugins\generic\premiumSubmissionHelper;

use APP\core\Application;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use APP\plugins\generic\premiumSubmissionHelper\classes\PremiumSubmissionHelperLogDAO;

class PremiumSubmissionHelperPlugin extends GenericPlugin
{
    protected const ALLOWED_ROLES = [
        ROLE_ID_MANAGER,
        ROLE_ID_SUB_EDITOR,
        ROLE_ID_AUTHOR
    ];

    /**
     * Enregistre le plugin
     */
    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);

        if ($success && $this->getEnabled()) {
            Hook::add('TemplateManager::display', [$this, 'injectAnalysisButton']);
            Hook::add('LoadHandler', [$this, 'setupAPIHandler']);
            Hook::add('TemplateManager::include', [$this, 'addScripts']);

            $this->import('classes.PremiumSubmissionHelperLog');
            $this->import('classes.PremiumSubmissionHelperLogDAO');

            $logDao = new PremiumSubmissionHelperLogDAO();
            DAORegistry::registerDAO('PremiumSubmissionHelperLogDAO', $logDao);

            $this->import('scheduledTasks.PremiumSubmissionHelperScheduledTask');
            Hook::add('Schema::get::premiumSubmissionHelperLog', [$this, 'addLogSchema']);
        }

        return $success;
    }

    public function getDisplayName(): string
    {
        return (string) __('plugins.generic.premiumSubmissionHelper');
    }

    public function getDescription(): string
    {
        return (string) __('plugins.generic.premiumSubmissionHelper.description');
    }

    public function getInstallSitePluginSettingsFile()
    {
        return 'plugins/generic/premiumSubmissionHelper/settings.xml';
    }

    public function injectAnalysisButton($hookName, $args)
    {
        $templateMgr = $args[0];
        $template = $args[1];

        if ($template !== 'submission/form/step1.tpl') {
            return false;
        }

        $request = Application::get()->getRequest();
        $user = $request->getUser();
        $context = $request->getContext();

        if (!$user || !$context) {
            return false;
        }

        $isPremiumUser = $this->isUserPremium($context->getId());
        $apiUrl = $request->getDispatcher()->url($request, ROUTE_PAGE, null, self::API_URL);

        $templateMgr->assign([
            'isPremiumUser' => $isPremiumUser,
            'apiUrl' => $apiUrl,
            'pluginUrl' => $request->getBaseUrl() . '/' . $this->getPluginPath(),
        ]);

        $templateMgr->display($this->getTemplateResource('premiumSubmissionHelper.tpl'));

        return false;
    }

    public function setupAPIHandler($hookName, $args)
    {
        $page = $args[0];
        $op = $args[1];
        $sourceFile =& $args[2];

        if ($page === self::API_URL) {
            $this->import('pages.APIHandler');
            $handler = new APIHandler($this);
            $handler->handle($op, $sourceFile);
            return true;
        }

        return false;
    }

    public function addScripts($hookName, $args)
    {
        $templateMgr = TemplateManager::getManager();
        $request = Application::get()->getRequest();
        $router = $request->getRouter();

        if (!$router) {
            return false;
        }

        $requestedPage = $router->getRequestedPage($request);
        $requestedOp = $router->getRequestedOp($request);

        if ($requestedPage !== 'submission' || $requestedOp !== 'wizard') {
            return false;
        }

        $templateMgr->addStyleSheet(
            'premiumSubmissionHelperStyles',
            $request->getBaseUrl() . '/' . $this->getPluginPath() . '/styles/premiumSubmissionHelper.css',
            ['contexts' => 'backend', 'priority' => STYLE_SEQUENCE_LAST]
        );

        $templateMgr->addJavaScript(
            'premiumSubmissionHelperScripts',
            $request->getBaseUrl() . '/' . $this->getPluginPath() . '/js/main.js',
            ['contexts' => ['frontend'], 'priority' => STYLE_SEQUENCE_LAST]
        );

        return false;
    }

    public function isUserPremium(int $contextId): bool
    {
        $request = Application::get()->getRequest();
        $user = $request->getUser();
        if (!$user) {
            return false;
        }

        $allowedRoles = [ROLE_ID_MANAGER, ROLE_ID_SUB_EDITOR, ROLE_ID_SITE_ADMIN];
        $userRoles = $user->getRoles($contextId);

        foreach ($userRoles as $role) {
            if (in_array($role->getRoleId(), $allowedRoles, true)) {
                return true;
            }
        }

        return false;
    }
}
