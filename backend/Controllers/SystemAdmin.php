<?php


namespace Okay\Admin\Controllers;


use Composer\InstalledVersions;

class SystemAdmin extends IndexAdmin
{
    private const CONFIG_TOGGLES = [
        'debug_mode' => [
            'section' => 'php',
            'label_key' => 'system_config_debug_mode',
            'hint_key' => 'system_config_debug_mode_hint',
        ],
        'dev_mode' => [
            'section' => 'design',
            'label_key' => 'system_config_dev_mode',
            'hint_key' => 'system_config_dev_mode_hint',
        ],
        'smarty_compile_check' => [
            'section' => 'smarty',
            'label_key' => 'system_config_smarty_compile_check',
            'hint_key' => 'system_config_smarty_compile_check_hint',
        ],
        'smarty_force_compile' => [
            'section' => 'smarty',
            'label_key' => 'system_config_smarty_force_compile',
            'hint_key' => 'system_config_smarty_force_compile_hint',
        ],
        'scripts_defer' => [
            'section' => 'design',
            'label_key' => 'system_config_scripts_defer',
            'hint_key' => 'system_config_scripts_defer_hint',
        ],
        'disable_tpl_mod' => [
            'section' => 'design',
            'label_key' => 'system_config_disable_tpl_mod',
            'hint_key' => 'system_config_disable_tpl_mod_hint',
        ],
    ];

    /*Информация о системе*/
    public function fetch()
    {
        if ($this->request->method('post') && $this->request->post('save_system_config')) {
            try {
                $this->saveConfigToggles();
                $this->postRedirectGet->storeMessageSuccess('saved');
            } catch (\Throwable $e) {
                $this->postRedirectGet->storeMessageError('system_config_save_error');
            }

            $this->response->redirectTo($this->request->getRootUrl() . '/backend/index.php?controller=SystemAdmin');
            return;
        }

        $phpVersion = phpversion();
        $allExtensions = get_loaded_extensions();
        natcasesort($allExtensions);
        $allExtensions = array_values($allExtensions);

        $iniParams = [];
        $requestIni = [
            'display_errors',
            'memory_limit',
            'post_max_size',
            'max_input_time',
            'max_file_uploads',
            'max_execution_time',
            'upload_max_filesize',
            'max_input_vars'
        ];

        foreach ($requestIni as $ini) {
            $iniParams[$ini] = ini_get($ini);
        }

        $sqlInfo = $this->db->getServerInfo();
        $serverIp = $_SERVER['SERVER_ADDR'] ?? gethostbyname(gethostname());

        $this->design->assign('sql_info', $sqlInfo);
        $this->design->assign('php_version', $phpVersion);
        $this->design->assign('all_extensions', $allExtensions);
        $this->design->assign('ini_params', $iniParams);
        $this->design->assign('server_ip', $serverIp);
        $this->design->assign('runtime_info', $this->getRuntimeInfo());
        $this->design->assign('paths_info', $this->getPathsInfo());
        $this->design->assign('config_toggles', $this->getConfigToggles());
        $this->design->assign('composer_sections', $this->getComposerSections());

        $this->response->setContent($this->design->fetch('settings_system.tpl'));
    }

    private function getConfigToggles(): array
    {
        $configToggles = [];

        foreach (self::CONFIG_TOGGLES as $name => $toggle) {
            $configToggles[] = [
                'name' => $name,
                'value' => (bool) $this->config->get($name),
                'label_key' => $toggle['label_key'],
                'hint_key' => $toggle['hint_key'],
            ];
        }

        return $configToggles;
    }

    private function getRuntimeInfo(): array
    {
        $rootPackage = class_exists(InstalledVersions::class) ? InstalledVersions::getRootPackage() : [];
        $installedPackagesCount = class_exists(InstalledVersions::class)
            ? count(InstalledVersions::getInstalledPackages())
            : 0;

        return [
            ['label_key' => 'system_cms_version', 'value' => $this->config->version],
            ['label_key' => 'system_project_package', 'value' => $rootPackage['name'] ?? 'okaycms/okaycms'],
            ['label_key' => 'system_project_version', 'value' => $rootPackage['pretty_version'] ?? '-'],
            [
                'label_key' => 'system_project_reference',
                'value' => !empty($rootPackage['reference']) ? mb_substr($rootPackage['reference'], 0, 10) : '-',
            ],
            ['label_key' => 'system_installed_packages_total', 'value' => $installedPackagesCount],
            ['label_key' => 'system_php_sapi', 'value' => PHP_SAPI],
            ['label_key' => 'system_server_software', 'value' => $_SERVER['SERVER_SOFTWARE'] ?? '-'],
            ['label_key' => 'system_operating_system', 'value' => php_uname('s') . ' ' . php_uname('r')],
            ['label_key' => 'system_root_url', 'value' => $this->request->getRootUrl()],
            ['label_key' => 'system_debug_mode', 'value' => (bool) $this->config->get('debug_mode'), 'is_bool' => true],
            [
                'label_key' => 'system_opcache',
                'value' => extension_loaded('Zend OPcache') && (bool) ini_get('opcache.enable'),
                'is_bool' => true,
            ],
        ];
    }

    private function getPathsInfo(): array
    {
        $paths = [
            'cache',
            'compiled',
            'backend/design/compiled',
            'files',
        ];

        $result = [];
        foreach ($paths as $path) {
            $absolutePath = realpath($path) ?: dirname(__DIR__, 2) . '/' . $path;
            $result[] = [
                'label' => $path,
                'path' => $absolutePath,
                'writable' => is_writable($path),
            ];
        }

        return $result;
    }

    private function getComposerSections(): array
    {
        $composerPath = dirname(__DIR__, 2) . '/composer.json';
        if (!is_file($composerPath)) {
            return [];
        }

        $composer = json_decode((string) file_get_contents($composerPath), true);
        if (!is_array($composer)) {
            return [];
        }

        $sections = [
            'require' => [
                'title_key' => 'system_direct_packages_title',
                'packages' => $this->mapComposerPackages($composer['require'] ?? []),
            ],
            'require-dev' => [
                'title_key' => 'system_dev_packages_title',
                'packages' => $this->mapComposerPackages($composer['require-dev'] ?? []),
            ],
        ];

        return array_filter($sections, static function (array $section): bool {
            return !empty($section['packages']);
        });
    }

    private function mapComposerPackages(array $packages): array
    {
        $result = [];

        foreach ($packages as $package => $constraint) {
            if ($package === 'php' || strpos($package, 'ext-') === 0) {
                continue;
            }

            $installedVersion = '-';
            $reference = '-';

            if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled($package)) {
                $installedVersion = InstalledVersions::getPrettyVersion($package) ?: '-';
                $packageReference = InstalledVersions::getReference($package);
                if (!empty($packageReference)) {
                    $reference = mb_substr($packageReference, 0, 10);
                }
            }

            $result[] = [
                'name' => $package,
                'constraint' => (string) $constraint,
                'installed' => $installedVersion,
                'reference' => $reference,
            ];
        }

        return $result;
    }

    private function saveConfigToggles(): void
    {
        $toggleValues = [];

        foreach (self::CONFIG_TOGGLES as $name => $toggle) {
            $toggleValues[$name] = $this->request->post($name, 'bool');
        }

        $this->writeMasterConfigValues($toggleValues);
        $this->removeLocalConfigOverrides(array_keys($toggleValues));
    }

    private function writeMasterConfigValues(array $toggleValues): void
    {
        $configContent = file_get_contents($this->config->configFile);
        if ($configContent === false) {
            throw new \RuntimeException('Cannot read config.php');
        }

        foreach ($toggleValues as $name => $value) {
            $pattern = '/^[ \t]*;?[ \t]*' . preg_quote($name, '/') . '[ \t]*=.*/mi';
            $replacement = $name . ' = ' . $this->formatIniValue($value);

            if (preg_match($pattern, $configContent)) {
                $configContent = (string) preg_replace($pattern, $replacement, $configContent, 1);
                continue;
            }

            $configContent = $this->insertValueIntoSection(
                $configContent,
                self::CONFIG_TOGGLES[$name]['section'],
                $replacement
            );
        }

        if (file_put_contents($this->config->configFile, $configContent, LOCK_EX) === false) {
            throw new \RuntimeException('Cannot write config.php');
        }
    }

    private function insertValueIntoSection(string $configContent, string $sectionName, string $line): string
    {
        $sectionHeader = '[' . $sectionName . ']';
        $sectionPosition = strpos($configContent, $sectionHeader);
        if ($sectionPosition === false) {
            throw new \RuntimeException("Cannot find section [{$sectionName}] in config.php");
        }

        $nextSectionPosition = strpos($configContent, "\n[", $sectionPosition + strlen($sectionHeader));
        if ($nextSectionPosition === false) {
            $nextSectionPosition = strlen($configContent);
        }

        $insertChunk = $line . PHP_EOL;
        return substr($configContent, 0, $nextSectionPosition)
            . $insertChunk
            . substr($configContent, $nextSectionPosition);
    }

    private function removeLocalConfigOverrides(array $toggleNames): void
    {
        if (!is_file($this->config->configLocalFile)) {
            return;
        }

        $configSections = $this->loadConfigSections($this->config->configLocalFile);
        $hasChanges = false;

        foreach ($toggleNames as $name) {
            $section = self::CONFIG_TOGGLES[$name]['section'];

            if (!isset($configSections[$section][$name])) {
                continue;
            }

            unset($configSections[$section][$name]);
            if (empty($configSections[$section])) {
                unset($configSections[$section]);
            }

            $hasChanges = true;
        }

        if ($hasChanges) {
            $this->writeConfigSections($this->config->configLocalFile, $configSections);
        }
    }

    private function loadConfigSections(string $configFile): array
    {
        $configContent = file_get_contents($configFile);
        if ($configContent === false) {
            throw new \RuntimeException("Cannot read {$configFile}");
        }

        if (trim($configContent) === '') {
            return [];
        }

        $configSections = parse_ini_string($configContent, true, INI_SCANNER_TYPED);
        if ($configSections === false) {
            throw new \RuntimeException("Cannot parse {$configFile}");
        }

        return $configSections;
    }

    private function writeConfigSections(string $configFile, array $configSections): void
    {
        $globalLines = [];
        $sectionLines = [];

        foreach ($configSections as $section => $values) {
            if (!is_array($values)) {
                $globalLines[] = $section . ' = ' . $this->formatIniValue($values);
                continue;
            }

            $sectionLines[] = '[' . $section . ']';
            foreach ($values as $key => $value) {
                if (is_array($value)) {
                    continue;
                }

                $sectionLines[] = $key . ' = ' . $this->formatIniValue($value);
            }
            $sectionLines[] = '';
        }

        $configContent = [';<? exit(); ?>', ''];

        if (!empty($globalLines)) {
            $configContent = array_merge($configContent, $globalLines, ['']);
        }

        $configContent = array_merge($configContent, $sectionLines);
        $configContent = rtrim(implode(PHP_EOL, $configContent)) . PHP_EOL;

        if (file_put_contents($configFile, $configContent, LOCK_EX) === false) {
            throw new \RuntimeException("Cannot write {$configFile}");
        }
    }

    private function formatIniValue($value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return '"' . addcslashes((string) $value, "\\\"") . '"';
    }
}
