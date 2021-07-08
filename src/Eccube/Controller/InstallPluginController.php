<?php

/*
 * This file is part of EC-CUBE
 *
 * Copyright(c) EC-CUBE CO.,LTD. All Rights Reserved.
 *
 * http://www.ec-cube.co.jp/
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Eccube\Controller;

use Eccube\Controller\Install\InstallController;
use Eccube\Entity\Plugin;
use Eccube\Exception\PluginException;
use Eccube\Service\PluginService;
use Eccube\Service\SystemService;
use Eccube\Util\CacheUtil;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;

class InstallPluginController extends InstallController
{
    /** @var CacheUtil */
    protected $cacheUtil;

    public function __construct(CacheUtil $cacheUtil)
    {
        $this->cacheUtil = $cacheUtil;
    }

    /**
     * プラグインを有効にします。
     *
     * @Route("/install/plugin/{code}/enable", requirements={"code" = "\w+"}, name="install_plugin_enable",  methods={"PUT"})
     *
     * @param SystemService $systemService
     * @param PluginService $pluginService
     * @param string $code
     *
     * @return JsonResponse
     *
     * @throws PluginException
     */
    public function pluginEnable(SystemService $systemService, PluginService $pluginService, $code)
    {
        $this->isTokenValid();
        // トランザクションチェックファイルの有効期限を確認する
        if (!$this->isValidTransaction()) {
            throw new NotFoundHttpException();
        }

        /** @var Plugin $Plugin */
        $Plugin = $this->entityManager->getRepository(Plugin::class)->findOneBy(['code' => $code]);
        $log = null;
        // プラグインが存在しない場合は無視する
        if ($Plugin !== null) {
            $systemService->switchMaintenance(true); // auto_maintenanceと設定されたファイルを生成
            $systemService->disableMaintenance(SystemService::AUTO_MAINTENANCE);

            try {
                ob_start();

                if ($Plugin->isEnabled()) {
                    $pluginService->disable($Plugin);
                } else {
                    $pluginService->enable($Plugin);
                }

            } finally {
                $log = ob_get_clean();
                while (ob_get_level() > 0) {
                    ob_end_flush();
                }
            }

            $this->cacheUtil->clearCache();
            return $this->json(['success' => true, 'log' => $log]);
        } else {
            return $this->json(['success' => false, 'log' => $log]);
        }
    }

    /**
     * トランザクションファイルを削除し, 管理画面に遷移します.
     *
     * @Route("/install/plugin/redirect", name="install_plugin_redirect")
     *
     * @return RedirectResponse
     */
    public function redirectAdmin()
    {
        $this->cacheUtil->clearCache();

        // トランザクションファイルを削除する
        $projectDir = $this->getParameter('kernel.project_dir');
        $transaction = $projectDir.parent::TRANSACTION_CHECK_FILE;
        if (file_exists($transaction)) {
            unlink($transaction);
        }

        return $this->redirectToRoute('admin_homepage');
    }

    /**
     * トランザクションチェックファイルの有効期限を確認する
     *
     * @return bool
     */
    public function isValidTransaction()
    {
        $projectDir = $this->getParameter('kernel.project_dir');
        if (!file_exists($projectDir.parent::TRANSACTION_CHECK_FILE)) {
            return false;
        }

        $transaction_checker = file_get_contents($projectDir.parent::TRANSACTION_CHECK_FILE);

        return $transaction_checker >= time();
    }
}
