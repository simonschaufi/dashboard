<?php
namespace TYPO3\CMS\Dashboard\Controller;

/***************************************************************
 *
 *  Copyright notice
 *
 *  (c) 2015
 *
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Extbase\Object\ObjectManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Backend\View\BackendTemplateView;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Lang\LanguageService;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Core\Utility\ArrayUtility;
use TYPO3\CMS\Form\Service\TranslationService;
use TYPO3\CMS\Backend\Utility\BackendUtility;

/**
 * DashboardController
 */
class DashboardController extends \TYPO3\CMS\Extbase\Mvc\Controller\ActionController
{

    /**
     * @var array
     */
    protected $dashboardSettings;

    /**
     * Default View Container
     *
     * @var BackendTemplateView
     */
    protected $defaultViewObjectName = BackendTemplateView::class;

    /**
     * dashboardRepository
     *
     * @var \TYPO3\CMS\Dashboard\Domain\Repository\DashboardRepository
     * @inject
     */
    protected $dashboardRepository = null;

    /**
     * dashboardWidgetSettingsRepository
     *
     * @var \TYPO3\CMS\Dashboard\Domain\Repository\DashboardWidgetSettingsRepository
     * @inject
     */
    protected $dashboardWidgetSettingsRepository = null;

    /**
     * dashboard
     *
     * @var \TYPO3\CMS\Dashboard\Domain\Model\Dashboard
     */
    protected $dashboard = null;

    /**
     * Initialize action
     */
    public function initializeAction()
    {
        $querySettings = $this->objectManager->get('TYPO3\\CMS\\Extbase\\Persistence\\Generic\\Typo3QuerySettings');
        $querySettings->setRespectStoragePage(false);
        $this->dashboardRepository->setDefaultQuerySettings($querySettings);

        $configurationManager = GeneralUtility::makeInstance(ObjectManager::class)
            ->get(ConfigurationManagerInterface::class);
        $this->dashboardSettings = $configurationManager
            ->getConfiguration(ConfigurationManagerInterface::CONFIGURATION_TYPE_FRAMEWORK, 'dashboard', 'dashboardmod1');

        if ($this->request->hasArgument('id')) {
            $this->dashboard = $this->dashboardRepository->findByUid($this->request->getArgument('id'));
            if ($this->dashboard->getBeUser()->getuid() != $this->getBackendUser()->user['uid']) {
                throw new Exception("Access denied to selected dashboard", 1);
            }
        } else {
            $this->dashboard = $this->dashboardRepository->findByBeuser($this->getBackendUser()->user['uid'])->getFirst();
        };
    }

    /**
     * action index
     *
     * @return void
     */
    public function indexAction()
    {
        $this->registerDocheaderMenu();
        $this->registerDocheaderButtons();
        $this->view->getModuleTemplate()->setModuleName($this->request->getPluginName() . '_' . $this->request->getControllerName());
        $this->view->getModuleTemplate()->setFlashMessageQueue($this->controllerContext->getFlashMessageQueue());

        $this->getPageRenderer()->addRequireJsConfiguration(
            [
                'paths' => [
                    'lodash' => '../typo3conf/ext/dashboard/Resources/Public/JavaScript/Backend/lodash.min',
                    'gridstack' => '../typo3conf/ext/dashboard/Resources/Public/JavaScript/Backend/gridstack.min',
                ],
                'shim' => [
                    'deps' => ['lodash', 'jquery'],
                    'gridstack' => ['exports' => 'gridstack'],
                ],
            ]
        );
        $this->getPageRenderer()->addRequireJsConfiguration(
            [
                'paths' => [
                    'jquery-ui' => '../typo3conf/ext/dashboard/Resources/Public/JavaScript/Contrib/jquery-ui',
                    'gridstackjqueryui' => '../typo3conf/ext/dashboard/Resources/Public/JavaScript/Backend/gridstack.jQueryUI.min',
                ],
                'shim' => [
                    'deps' => ['lodash', 'jquery', 'jquery-ui', 'gridstack'],
                    'gridstackjqueryui' => ['exports' => 'gridstackjqueryui'],
                ],
            ]
        );

        $this->view->assign('stylesheets', $this->resolveResourcePaths($this->dashboardSettings['settings']['stylesheets']));
        $this->view->assign('dynamicRequireJsModules', $this->dashboardSettings['settings']['dynamicRequireJsModules']);
        $this->view->assign('dashboardAppInitialData', $this->getDashboardAppInitialData());
        if (!empty($this->dashboardSettings['settings']['javaScriptTranslationFile'])) {
            $this->getPageRenderer()->addInlineLanguageLabelFile($this->dashboardSettings['settings']['javaScriptTranslationFile']);
        }
        $this->view->assign('dashboard', $this->dashboard);
    }

    /**
     * action change
     *
     * @return string
     */
    public function changeAction()
    {
        $getVars = $this->request->getArguments();
        $items = $getVars['items'];
        if (!empty($items) && is_array($items)) {
            foreach ($items as $index => $item) {
                $widget = $this->dashboardWidgetSettingsRepository->findByUid($item['uid']);
                $widget->setX($item['x']);
                $widget->setY($item['y']);
                $widget->setWidth($item['width']);
                $widget->setHeight($item['height']);
                $this->dashboardWidgetSettingsRepository->update($widget);
            }
            $this->objectManager
                ->get(\TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager::class)
                ->persistAll();
        }
        return 'sent string was: ' . print_r($getVars['items'], true);
    }

    /**
     * action createWidget
     *
     * @return string
     */
    public function createWidgetAction()
    {
        $getVars = $this->request->getArguments();
        if (is_object($this->dashboard)) {
            $storagePid = $this->dashboardSettings['persistence']['storagePid'];
            $widgetType = $getVars['widgetType'];
            $widgetSettings = $this->getWidgetSettings($widgetType);
            $width = (isset($widgetSettings['defaultWidth'])) ? $widgetSettings['defaultWidth'] : 3;
            $height = (isset($widgetSettings['defaultHeight'])) ? $widgetSettings['defaultHeight'] : 5;
            $overrideVals = '&overrideVals[tx_dashboard_domain_model_dashboardwidgetsettings][dashboard]=' . $this->dashboard->getUid();
            $overrideVals .= '&overrideVals[tx_dashboard_domain_model_dashboardwidgetsettings][widget_identifier]=' . $getVars['widgetType'];
            $overrideVals .= '&overrideVals[tx_dashboard_domain_model_dashboardwidgetsettings][width]=' . $width;
            $overrideVals .= '&overrideVals[tx_dashboard_domain_model_dashboardwidgetsettings][height]=' . $height;
            $overrideVals .= '&overrideVals[tx_dashboard_domain_model_dashboardwidgetsettings][y]=' . $this->getNextRow($this->dashboard->getUid());
            $overrideVals .= '&overrideVals[tx_dashboard_domain_model_dashboardwidgetsettings][x]=0';
            $params = '&edit[tx_dashboard_domain_model_dashboardwidgetsettings][' . $storagePid . ']=new' . $overrideVals;

            $returnUrl = urlencode($this->controllerContext->getUriBuilder()->uriFor('index', ['id' => $this->dashboard->getUid()]));
            return BackendUtility::getModuleUrl('record_edit') . $params . '&returnUrl=' . $returnUrl;
        }
        return 'widgetType: ' . $getVars['widgetType'];
    }

    /**
     * action change
     *
     * @return string
     */
    public function createAction()
    {
        $getVars = $this->request->getArguments();

        if (isset($GLOBALS['BE_USER']->user['uid'])) {
            $beUserUid = (int)$GLOBALS['BE_USER']->user['uid'];

            $beUserRepository = $this->objectManager->get(
                \TYPO3\CMS\Beuser\Domain\Repository\BackendUserRepository::class
            );
            $beUser = $beUserRepository->findByUid($beUserUid);
            if ($beUser !== null) {
                $newDashboard = $this->objectManager->get(\TYPO3\CMS\Dashboard\Domain\Model\Dashboard::class);
                $newDashboard->setTitle($getVars['dashboardName']);
                $newDashboard->setBeuser($beUser);
                $this->dashboardRepository->add($newDashboard);
                $this->objectManager
                    ->get(\TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager::class)
                    ->persistAll();
            }
            // return 'Would create dashboard with name: ' . $getVars['dashboardName'];
            return $this->controllerContext->getUriBuilder()->uriFor('index', ['id' => $newDashboard->getUid()]);
        }
        return false; //$this->controllerContext->getUriBuilder()->uriFor('index');
    }

    /**
     * action renderWidget
     *
     * @return string
     */
    public function renderWidgetAction()
    {
        $getVars = $this->request->getArguments();
        $widgetId = $getVars['widgetId'];
        if (!empty($widgetId) && (int)$widgetId > 0) {
            $widget = $this->dashboardWidgetSettingsRepository->findByUid($widgetId);
            if ($widget) {
                $widgetConfiguration = $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['dashboard']['widgets'][$widget->getWidgetIdentifier()];
                $widgetClassName = $widgetConfiguration['class'];
                if (class_exists($widgetClassName)) {
                    $widgetClass = $this->objectManager->get($widgetClassName);
                    try {
                        return $widgetClass->render($widget);
                    } catch (\Exception $e) {
                        $localizedError = $this
                            ->getLanguageService()
                            ->sL('LLL:EXT:dashboard/Resources/Private/Language/locallang.xlf:error.' . $e->getCode());
                        $localizedError = strlen($localizedError) > 0 ? $localizedError : $e->getMessage();
                        return '<div class="alert alert-danger">' . $localizedError . '</div>';
                    }
                } else {
                    return 'Class : ' . $widgetClassName .' could not be found!';
                }
            } else {
                return 'Widget [' . $widgetId . '] was not found..';
            }
        }
        return 'hmm, nothing catched returnstring';
    }

    /**
     * Registers the menu of dashboards into the docheader
     *
     * @throws \InvalidArgumentException
     */
    protected function registerDocheaderMenu()
    {
        // Dashboards
        $dashboards = $this->dashboardRepository->findByBeuser((int)$GLOBALS['BE_USER']->user['uid']);
        if (!empty($dashboards)) {
            $dashboardMenu = $this->view->getModuleTemplate()->getDocHeaderComponent()->getMenuRegistry()->makeMenu();
            $dashboardMenu->setIdentifier('_dsahboardSelector');
            // $dashboardMenu->setLabel('Select dashboard');
            foreach ($dashboards as $index => $dashboard) {
                $menuItem = $dashboardMenu->makeMenuItem()
                    ->setTitle($dashboard->getTitle())
                    ->setHref(
                        $this->controllerContext->getUriBuilder()->uriFor('index', ['id' => $dashboard->getUid()])
                    );
                if ($dashboard->getUid() === $this->dashboard->getUid()) {
                    $menuItem->setActive(true);
                }
                $dashboardMenu->addMenuItem($menuItem);
            }
            $this->view->getModuleTemplate()->getDocHeaderComponent()->getMenuRegistry()->addMenu($dashboardMenu);
        }
    }

    /**
     * Registers the Icons into the docheader
     *
     * @throws \InvalidArgumentException
     */
    protected function registerDocheaderButtons()
    {
        /** @var ButtonBar $buttonBar */
        $buttonBar = $this->view->getModuleTemplate()->getDocHeaderComponent()->getButtonBar();
        $getVars = $this->request->getArguments();

        // New dashboard button
        $newDashboardButton = $buttonBar->makeLinkButton()
            ->setDataAttributes(['identifier' => 'newDashboard'])
            ->setHref('#')
            ->setTitle($this->getLanguageService()->sL('LLL:EXT:dashboard/Resources/Private/Language/locallang.xlf:dashboardManager.create_new_dashboard'))
            ->setIcon($this->view->getModuleTemplate()->getIconFactory()->getIcon('actions-document-new', Icon::SIZE_SMALL));
        $buttonBar->addButton($newDashboardButton, ButtonBar::BUTTON_POSITION_LEFT, 1);

        // Edit dashboard button
        $newDashboardButton = $buttonBar->makeLinkButton()
            ->setDataAttributes(['identifier' => 'editDashboard'])
            ->setHref('#')
            ->setTitle($this->getLanguageService()->sL('LLL:EXT:dashboard/Resources/Private/Language/locallang.xlf:dashboardManager.edit_dashboard'))
            ->setIcon($this->view->getModuleTemplate()->getIconFactory()->getIcon('actions-document-open', Icon::SIZE_SMALL));
        $buttonBar->addButton($newDashboardButton, ButtonBar::BUTTON_POSITION_LEFT, 1);

        // New dashboard widget setting button
        if (is_object($this->dashboard)) {
            $newWidgetButton = $buttonBar->makeLinkButton()
                ->setDataAttributes(
                    [
                        'identifier' => 'newDashboardWidgetSetting',
                        'dashboardid' => $this->dashboard->getUid()
                    ]
                )
                ->setHref('#')
                ->setTitle(
                    $this->getLanguageService()->sL(
                        'LLL:EXT:dashboard/Resources/Private/Language/locallang.xlf:dashboardManager.create_new_dashboard_widget_setting'
                    )
                )
                ->setIcon(
                    $this->view->getModuleTemplate()->getIconFactory()->getIcon(
                        'actions-document-new',
                        Icon::SIZE_SMALL
                    )
                );
            $buttonBar->addButton($newWidgetButton, ButtonBar::BUTTON_POSITION_LEFT, 10);
        }
    }

    /**
     * Returns the json encoded data which is used by the dashboard
     * JavaScript app.
     *
     * @return string
     */
    protected function getDashboardAppInitialData(): string
    {
        $dashboardAppInitialData = [
            'selectableWidgetTypesConfiguration' => $this->getSelectableWidgets(),
            'selectablePrototypesConfiguration' => $this->dashboardSettings['settings']['selectablePrototypesConfiguration'],
            'endpoints' => [
                'create' => $this->controllerContext->getUriBuilder()->uriFor('create'),
                'createWidget' => $this->controllerContext->getUriBuilder()->uriFor('createWidget'),
                'change' => $this->controllerContext->getUriBuilder()->uriFor('change'),
                'index' => $this->controllerContext->getUriBuilder()->uriFor('index', ['id' => $this->dashboard->getUid()]),
                'renderWidget' => $this->controllerContext->getUriBuilder()->uriFor('renderWidget'),
                'editDashboard' => $this->getEditDashboardEndpoint(),
            ],
        ];

        if (is_object($this->dashboard)) {
            $dashboardAppInitialData['dashboard'] = [
                'id' => $this->dashboard->getUid(),
                'title' => $this->dashboard->getTitle(),
            ];
        }

        $dashboardAppInitialData = ArrayUtility::reIndexNumericArrayKeysRecursive($dashboardAppInitialData);
        $dashboardAppInitialData = TranslationService::getInstance()->translateValuesRecursive(
            $dashboardAppInitialData,
            $this->dashboardSettings['settings']['translationFile']
        );

        return json_encode($dashboardAppInitialData);
    }

    /**
     * Returns array of items configured for widget_identifier
     *
     * @return array
     */
    protected function getSelectableWidgets(): array
    {
        $items = $GLOBALS['TCA']['tx_dashboard_domain_model_dashboardwidgetsettings']['columns']['widget_identifier']['config']['items'];
        unset($items['0']);
        if (!empty($items) && is_array($items)) {
            foreach ($items as $index => $values) {
                $items[$index]['0'] = $this->getLanguageService()->sL($values['0']);
            }
        }
        return $items;
    }

    /**
     * Returns array of item configured for widget_identifier
     *
     * @param string widgetIdentifier
     *
     * @return array
     */
    protected function getWidgetSettings(string $widgetIdentifier): array
    {
        $widgetSettings = [];

        $selectableWidgets = $this->getSelectableWidgets();
        if (isset($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['dashboard']['widgets'][$widgetIdentifier])) {
            $widgetSettings = $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['dashboard']['widgets'][$widgetIdentifier];
        }
        return $widgetSettings;
    }

    /**
     * Returns next available "row"
     *
     * @param integer $dasboardId
     *
     * @return integer
     */
    protected function getNextRow(int $dasboardId): int
    {
        $retval = 0;

        /** @var QueryBuilder $queryBuilder */
        $queryBuilder =
            GeneralUtility::makeInstance(
                \TYPO3\CMS\Core\Database\ConnectionPool::class
            )->getQueryBuilderForTable('tx_dashboard_domain_model_dashboardwidgetsettings');

        $result = $queryBuilder
            ->select('y', 'height')
            ->from('tx_dashboard_domain_model_dashboardwidgetsettings')
            ->where($queryBuilder->expr()->eq('dashboard', $dasboardId))
            ->andWhere($queryBuilder->expr()->eq('deleted', 0))
            ->orderBy('y', 'DESC')
            ->addOrderBy('height', 'DESC')
            ->execute()
            ->fetch();

        if ($result) {
            $retval = $result['y'] + $result['height'];
        }
        return $retval;
    }

    /**
     * Returns edit url for this dashboard
     *
     * @return string
     */
    protected function getEditDashboardEndpoint()
    {
        $params = '&edit[tx_dashboard_domain_model_dashboard][' . $this->dashboard->getUid() . ']=edit';
        $returnUrl = urlencode($this->controllerContext->getUriBuilder()->uriFor('index', ['id' => $this->dashboard->getUid()]));
        return BackendUtility::getModuleUrl('record_edit') . $params . '&returnUrl=' . $returnUrl;
    }

    /**
     * Convert arrays with EXT: resource paths to web paths
     *
     * Input:
     * [
     *   100 => 'EXT:form/Resources/Public/Css/form.css'
     * ]
     *
     * Output:
     *
     * [
     *   0 => 'typo3/sysext/form/Resources/Public/Css/form.css'
     * ]
     *
     * @param array $resourcePaths
     * @return array
     */
    protected function resolveResourcePaths(array $resourcePaths): array
    {
        $return = [];
        foreach ($resourcePaths as $resourcePath) {
            $fullResourcePath = GeneralUtility::getFileAbsFileName($resourcePath);
            $resourcePath = PathUtility::getAbsoluteWebPath($fullResourcePath);
            if (empty($resourcePath)) {
                continue;
            }
            $return[] = $resourcePath;
        }

        return $return;
    }

    /**
     * Returns the current BE user.
     *
     * @return BackendUserAuthentication
     */
    protected function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }

    /**
     * Returns the Language Service
     *
     * @return LanguageService
     */
    protected function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }

    /**
     * Returns the page renderer
     *
     * @return PageRenderer
     */
    protected function getPageRenderer(): PageRenderer
    {
        return GeneralUtility::makeInstance(PageRenderer::class);
    }

    /**
     * action list
     *
     * @return void
     */
    public function listAction()
    {

        
        /** @var $pageRenderer PageRenderer */
        $pageRenderer = GeneralUtility::makeInstance(PageRenderer::class);
        // $pageRenderer->loadRequireJsModule('TYPO3/CMS/Dashboard/GridList');

        $this->view->assignMultiple([
            'includeCssFiles' => $this->getIncludeCssFilesFromSettings(),
            'includeJsFiles' => $this->getIncludeJsFilesFromSettings()
        ]);

        if (isset($GLOBALS['BE_USER']->user['uid'])) {
            $beUserUid = (int)$GLOBALS['BE_USER']->user['uid'];

            $dashboards = $this->dashboardRepository->findByBeuser($beUserUid);

            if ($dashboards->count() == 0) {
                // Create a new dashboard if none exists (use a "template" when first dashboard is created?)
                $beUserRepository = $this->objectManager->get(\TYPO3\CMS\Beuser\Domain\Repository\BackendUserRepository::class);
                $beUser = $beUserRepository->findByUid($beUserUid);
                if ($beUser !== null) {
                    $defaultDashboardName = strlen(trim($beUser->getRealName())) > 0 ? $beUser->getRealName() : $beUser->getUserName();
                    $newDashboard = $this->objectManager->get(\TYPO3\CMS\Dashboard\Domain\Model\Dashboard::class);
                    $newDashboard->setTitle($defaultDashboardName . ' dashboard');
                    $newDashboard->setBeuser($beUser);
                    $newDashboard->addDashboardWidgetSetting($this->getExampleWidgetSettingObject());
                    $this->dashboardRepository->add($newDashboard);
                    $this->objectManager
                         ->get(\TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager::class)
                         ->persistAll();
                }
            }

            if ($this->request->hasArgument('dashboardUid')) {
                $dashboardCurrent = $this->dashboardRepository->findByUid($this->request->getArgument('dashboardUid'));
            } else {
                $dashboardCurrent = $this->dashboardRepository->findByBeuser($beUserUid)->getFirst();
            };

            // Get Storage Pid
            $configurationManager = GeneralUtility::makeInstance(ObjectManager::class)->get(ConfigurationManagerInterface::class);
            $configuration = $configurationManager->getConfiguration(ConfigurationManagerInterface::CONFIGURATION_TYPE_FRAMEWORK, 'dashboard', 'dashboardmod1');
            $storagePid = $configuration['persistence']['storagePid'];

            $dashboardWidgets = $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['dashboard']['widgets'];
            if (is_array($dashboardWidgets) && count($dashboardWidgets) > 0) {
                foreach ($dashboardWidgets as $index => $dashboardWidget) {
                    if ($dashboardCurrent !== null) {
                        $overrideVals = '&overrideVals[tx_dashboard_domain_model_dashboardwidgetsettings][dashboard]=' . $dashboardCurrent->getUid();
                        $overrideVals .= '&overrideVals[tx_dashboard_domain_model_dashboardwidgetsettings][state]=new';
                        $overrideVals .= '&overrideVals[tx_dashboard_domain_model_dashboardwidgetsettings][position]=last';
                        $overrideVals .= '&overrideVals[tx_dashboard_domain_model_dashboardwidgetsettings][widget_identifier]=' . $index;
                        $editOnClick = '&edit[tx_dashboard_domain_model_dashboardwidgetsettings]['.$storagePid.']=new' . $overrideVals;
                        $dashboardWidgets[$index]['addNewLink'] = \TYPO3\CMS\Backend\Utility\BackendUtility::editOnClick($editOnClick);
                        $dashboardWidgets[$index]['widget_identifier'] = $index;
                        if (substr($dashboardWidget['name'], 0, 4) == 'LLL:') {
                            $dashboardWidgets[$index]['name'] =    $GLOBALS['LANG']->sL($dashboardWidget['name']);
                        }
                        if (substr($dashboardWidget['description'], 0, 4) == 'LLL:') {
                            $dashboardWidgets[$index]['description'] =    $GLOBALS['LANG']->sL($dashboardWidget['description']);
                        }
                    }
                }
            }
            $this->view->assign('dashboardWidgets', $dashboardWidgets);

            if ($dashboards->getFirst() !== null) {
                $link = \TYPO3\CMS\Backend\Utility\BackendUtility::editOnClick('&edit[tx_dashboard_domain_model_dashboard][' . $dashboards->getFirst()->getUid() . ']=edit');
                $this->view->assign('link', $link);
            }
        }

        $this->view->assign('dashboards', $dashboards);
        $this->view->assign('dashboardCurrent', $dashboardCurrent);
    }

    /**
     * [getIncludeCssFilesFromSettings Includes css files defined in ts]
     *
     * @return array Array of files
     */
    private function getIncludeCssFilesFromSettings()
    {
        $includeCssFiles = array();
        if (!empty($this->settings['includeCssFiles']) && is_array($this->settings['includeCssFiles'])) {
            foreach ($this->settings['includeCssFiles'] as $key => $path) {
                $fileAbsFileName = \TYPO3\CMS\Core\Utility\GeneralUtility::getFileAbsFileName($path, true, true);
                $relativePathTo = \TYPO3\CMS\Core\Utility\PathUtility::getRelativePathTo($fileAbsFileName);
                $includeCssFiles[$key] = rtrim($relativePathTo, '/');
            }
        }
        return $includeCssFiles;
    }

    /**
     * [getIncludeJsFilesFromSettings Includes css files defined in ts]
     *
     * @return array Array of files
     */
    private function getIncludeJsFilesFromSettings()
    {
        $includeJsFiles = array();
        foreach ($this->settings['includeJsFiles'] as $key => $path) {
            $fileAbsFileName = \TYPO3\CMS\Core\Utility\GeneralUtility::getFileAbsFileName($path, true, true);
            $relativePathTo = \TYPO3\CMS\Core\Utility\PathUtility::getRelativePathTo($fileAbsFileName);
            $includeJsFiles[$key] = rtrim($relativePathTo, '/');
        }
        return $includeJsFiles;
    }

    /**
     * Get a TYPO3 News RSS widget
     * @return \TYPO3\CMS\Dashboard\Domain\Model\DashboardWidgetSettings Settings for a TYPO3 News RSS widget
     */
    private function getExampleWidgetSettingObject()
    {
        // Create "example" dashboard widget setting
        $newDashboardWidgetSetting = $this->objectManager->get(
            \TYPO3\CMS\Dashboard\Domain\Model\DashboardWidgetSettings::class
        );
        $newDashboardWidgetSetting->setTitle('TYPO3 News');
        $newDashboardWidgetSetting->setWidgetIdentifier('41385600');
        $newDashboardWidgetSetting->setState('new');
        $newDashboardWidgetSetting->setSettingsFlexform('<?xml version="1.0" encoding="utf-8" standalone="yes" ?>
                <T3FlexForms>
                    <data>
                        <sheet index="sDEF">
                            <language index="lDEF">
                                <field index="settings.header">
                                    <value index="vDEF">TYPO3 News</value>
                                </field>
                                <field index="settings.feedUrl">
                                    <value index="vDEF">http://typo3.org/xml-feeds/rss.xml</value>
                                </field>
                                <field index="settings.feedLimit">
                                    <value index="vDEF">10</value>
                                </field>
                                <field index="settings.cacheLifetime">
                                    <value index="vDEF">10</value>
                                </field>
                            </language>
                        </sheet>
                    </data>
                </T3FlexForms>');
        return $newDashboardWidgetSetting;
    }
}
