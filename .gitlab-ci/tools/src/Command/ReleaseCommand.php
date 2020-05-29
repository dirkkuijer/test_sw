<?php


namespace Shopware\CI\Command;


use Aws\S3\S3MultiRegionClient;
use Composer\Semver\VersionParser;
use GuzzleHttp\Client;
use League\Flysystem\Adapter\Local;
use League\Flysystem\AwsS3v3\AwsS3Adapter;
use League\Flysystem\Filesystem;
use Shopware\CI\Service\ChangelogService;
use Shopware\CI\Service\CredentialService;
use Shopware\CI\Service\ReleasePrepareService;
use Shopware\CI\Service\ReleaseService;
use Shopware\CI\Service\TaggingService;
use Shopware\CI\Service\UpdateApiService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

abstract class ReleaseCommand extends Command
{
    /**
     * @var array|null
     */
    private $config;

    /**
     * @var Filesystem
     */
    private $deployFilesystem;

    protected function getConfig(InputInterface $input, OutputInterface $output): array
    {
        if ($this->config !== null) {
            return $this->config;
        }

        $config = [
            'minimumVersion' => $_SERVER['MINIMUM_VERSION'] ?? '6.2.1',
            'projectId' => $_SERVER['CI_PROJECT_ID'] ?? '',
            'gitlabBaseUri' => rtrim($_SERVER['CI_API_V4_URL'] ?? '', '/') . '/', // guzzle needs the slash
            'gitlabRemoteUrl' => $_SERVER['CI_REPOSITORY_URL'] ?? '',
            'gitlabApiToken' => $_SERVER['BOT_API_TOKEN'] ?? '',
            'targetBranch' => $_SERVER['TARGET_BRANCH'] ?? '',
            'manyReposBaseUrl' => $_SERVER['MANY_REPO_BASE_URL'] ?? '',
            'projectRoot' => $_SERVER['PROJECT_ROOT']  ?? dirname(__DIR__, 4),
            'deployFilesystem' => [
                'key' => $_SERVER['AWS_ACCESS_KEY_ID'] ?? '',
                'secret' => $_SERVER['AWS_SECRET_ACCESS_KEY'] ?? '',
                'bucket' => 'releases.s3.shopware.com',
                'publicDomain' => 'https://releases.shopware.com'
            ],
            'minor_branch' => $_SERVER['PLATFORM_BRANCH'] ?? '',
            'jira' => [
                'api_base_uri' => 'https://jira.shopware.com/rest/api/2/',
            ],
            'updateApiHost' => $_SERVER['UPDATE_API_HOST'] ?? ''
        ];

        $credentialService = new CredentialService();
        $jiraCredentials = $credentialService->getCredentials($input, $output);
        $config['jira'] = array_merge($config['jira'], $jiraCredentials);

        $stability = $input->hasOption('stability') ? $input->getOption('stability') : null;
        $stability = $input->hasOption('minimum-stability') ? $input->getOption('minimum-stability') : $stability;
        $stability = $stability ?? $_SERVER['STABILITY'] ?? null;

        if ($input->hasArgument('tag')) {
            $tag = $input->getArgument('tag');
            $config['tag'] = $tag;
            $stability = $stability ?? VersionParser::parseStability($config['tag']);
        }
        $config['stability'] = $stability;

        $repos = ['core', 'administration', 'storefront', 'elasticsearch', 'recovery'];
        $config['repos'] = [];
        foreach ($repos as $repo) {
            $config['repos'][$repo] = [
                'path' => $config['projectRoot'] . '/repos/' . $repo,
                'remoteUrl' => $config['manyReposBaseUrl'] . '/' . $repo
            ];
        }

        return $this->config = $config;
    }

    protected function getChangelogService(InputInterface $input, OutputInterface $output): ChangelogService
    {
        $config = $this->getConfig($input, $output);

        $jiraApiClient = new Client([
            'base_uri' => $config['jira']['api_base_uri'],
            'auth' => [$config['jira']['username'], $config['jira']['password']]
        ]);

        return new ChangelogService($jiraApiClient);
    }

    protected function getDeployFilesystem(InputInterface $input, OutputInterface $output): Filesystem
    {
        if ($this->deployFilesystem) {
            return $this->deployFilesystem;
        }

        $config = $this->getConfig($input, $output);

        if ($input->getOption('deploy')) {
            $s3Client = new S3MultiRegionClient([
                'credentials' => [
                    'key' => $config['deployFilesystem']['key'],
                    'secret' => $config['deployFilesystem']['secret']
                ],
                'version' => 'latest',
            ]);
            $adapter = new AwsS3Adapter($s3Client, $config['deployFilesystem']['bucket']);
            $this->deployFilesystem = new Filesystem($adapter, ['visibility' => 'public']);
        } else {
            $this->deployFilesystem = new Filesystem(new Local(dirname(__DIR__, 2) . '/deploy'));
        }

        return $this->deployFilesystem;
    }

    protected function getReleasePrepareService(InputInterface $input, OutputInterface $output): ReleasePrepareService
    {
        $config = $this->getConfig($input, $output);


        $artifactFilesystem = new Filesystem(new Local($config['projectRoot'] . '/artifacts'));

        return new ReleasePrepareService(
            $config,
            $this->getDeployFilesystem($input, $output),
            $artifactFilesystem,
            $this->getChangelogService($input, $output),
            new UpdateApiService($config['updateApiHost'])
        );
    }

    protected function getTaggingService(InputInterface $input, OutputInterface $output): TaggingService
    {
        $config = $this->getConfig($input, $output);
        $gitlabApiClient = new Client([
            'base_uri' => $config['gitlabBaseUri'],
            'headers' => [
                'Private-Token' => $config['gitlabApiToken'],
                'Content-TYpe' => 'application/json'
            ]
        ]);

        return new TaggingService(new VersionParser(), $config, $gitlabApiClient);
    }

    protected function getReleaseService(InputInterface $input, OutputInterface $output): ReleaseService
    {
        $config = $this->getConfig($input, $output);

        return new ReleaseService(
            $config,
            $this->getReleasePrepareService($input, $output),
            $this->getTaggingService($input, $output)
        );
    }
}