<?php

namespace Packrats\ImportFluxBB\Console;

use Packrats\ImportFluxBB\Importer\Avatars;
use Packrats\ImportFluxBB\Importer\Bans;
use Packrats\ImportFluxBB\Importer\Categories;
use Packrats\ImportFluxBB\Importer\Forums;
use Packrats\ImportFluxBB\Importer\ForumSubscriptions;
use Packrats\ImportFluxBB\Importer\Groups;
use Packrats\ImportFluxBB\Importer\InitialCleanup;
use Packrats\ImportFluxBB\Importer\PostMentionsUser;
use Packrats\ImportFluxBB\Importer\Posts;
use Packrats\ImportFluxBB\Importer\Reports;
use Packrats\ImportFluxBB\Importer\Topics;
use Packrats\ImportFluxBB\Importer\TopicSubscriptions;
use Packrats\ImportFluxBB\Importer\Users;
use Packrats\ImportFluxBB\Importer\Validation;
use Flarum\Console\AbstractCommand;
use Flarum\Extension\ExtensionManager;
use PDO;
use Symfony\Component\Console\Input\InputArgument;

class ImportFromFluxBB extends AbstractCommand
{
    /**
     * @var Users
     */
    private $users;
    /**
     * @var Avatars
     */
    private $avatars;
    /**
     * @var Categories
     */
    private $categories;
    /**
     * @var Forums
     */
    private $forums;
    /**
     * @var Topics
     */
    private $topics;
    /**
     * @var Posts
     */
    private $posts;
    /**
     * @var TopicSubscriptions
     */
    private $topicSubscriptions;
    /**
     * @var ForumSubscriptions
     */
    private $forumSubscriptions;
    /**
     * @var Groups
     */
    private $groups;
    /**
     * @var Bans
     */
    private $bans;
    /**
     * @var Reports
     */
    private $reports;
    /**
     * @var PostMentionsUser
     */
    private $postMentionsUser;
    /**
     * @var InitialCleanup
     */
    private $initialCleanup;
    /**
     * @var Validation
     */
    private $validation;
    /**
     * @var ExtensionManager
     */
    private $extensionManager;

    public function __construct(
        Users $users,
        Categories $categories,
        Forums $forums,
        Avatars $avatars,
        Topics $topics,
        Posts $posts,
        TopicSubscriptions $topicSubscriptions,
        ForumSubscriptions $forumSubscriptions,
        Groups $groups,
        Bans $bans,
        Reports $reports,
        PostMentionsUser $postMentionsUser,
        InitialCleanup $initialCleanup,
        Validation $validation,
        ExtensionManager $extensionManager
    ) {
        $this->users = $users;
        $this->categories = $categories;
        $this->forums = $forums;
        $this->avatars = $avatars;
        $this->topics = $topics;
        $this->posts = $posts;
        $this->topicSubscriptions = $topicSubscriptions;
        $this->forumSubscriptions = $forumSubscriptions;
        $this->groups = $groups;
        $this->bans = $bans;
        $this->reports = $reports;
        $this->postMentionsUser = $postMentionsUser;
        $this->initialCleanup = $initialCleanup;
        $this->validation = $validation;
        $this->extensionManager = $extensionManager;

        parent::__construct();
    }

    protected function configure()
    {
        // For inspiration see:
        // https://github.com/sineld/import-from-fluxbb-to-flarum
        // https://github.com/mondediefr/fluxbb_to_flarum
        // also https://github.com/pierres/ll/blob/fluxbb/FluxImport.php
        $this
            ->setName('app:import-from-fluxbb')
            ->setDescription('Import from FluxBB')
            ->addArgument('fluxbb-database', InputArgument::REQUIRED)
            ->addArgument('fluxbb-user', InputArgument::REQUIRED)
            ->addArgument('fluxbb-password', InputArgument::REQUIRED)
            ->addArgument('fluxbb-prefix', InputArgument::REQUIRED)
            ->addArgument('fluxbb-host', InputArgument::REQUIRED);
    }

    protected function fire()
    {
        $dsn = sprintf(
            'mysql:dbname=%s;host=localhost',
            $this->input->getArgument('fluxbb-database')
        );

        $fluxBBDatabase = new PDO(
            $dsn,
            $this->input->getArgument('fluxbb-user'),
            $this->input->getArgument('fluxbb-password')
        );

        $requiredExtensions = [
            'flarum-bbcode',
            'flarum-emoji',
            'flarum-mentions',
            'flarum-nicknames',
            'flarum-sticky',
            'flarum-subscriptions',
            'flarum-tags',
            'flarum-suspend',
            'flarum-lock',
            'migratetoflarum-old-passwords'
        ];
        foreach ($requiredExtensions as $requiredExtension) {
            if (!$this->extensionManager->isEnabled($requiredExtension)) {
                $this->error($requiredExtension . ' extension needs to be enabled');
                return;
            }
        }

        ini_set('memory_limit', '16G');

        $this->initialCleanup->execute($this->output);
        $this->users->execute($this->output, $fluxBBDatabase, $this->input->getArgument('fluxbb-prefix'));
        $this->avatars->execute($this->output, $fluxBBDatabase, $this->input->getArgument('fluxbb-prefix'), $this->input->getArgument('fluxbb-host'));
        $this->categories->execute($this->output, $fluxBBDatabase, $this->input->getArgument('fluxbb-prefix'));
        $this->forums->execute($this->output, $fluxBBDatabase, $this->input->getArgument('fluxbb-prefix'));
        $this->topics->execute($this->output, $fluxBBDatabase, $this->input->getArgument('fluxbb-prefix'));
        $this->posts->execute($this->output, $fluxBBDatabase, $this->input->getArgument('fluxbb-prefix'));
        $this->topicSubscriptions->execute($this->output, $fluxBBDatabase, $this->input->getArgument('fluxbb-prefix'));
        $this->forumSubscriptions->execute($this->output, $fluxBBDatabase, $this->input->getArgument('fluxbb-prefix'));
        $this->groups->execute($this->output, $fluxBBDatabase, $this->input->getArgument('fluxbb-prefix'));
        $this->bans->execute($this->output, $fluxBBDatabase, $this->input->getArgument('fluxbb-prefix'));
        $this->reports->execute($this->output, $fluxBBDatabase, $this->input->getArgument('fluxbb-prefix'));
        $this->postMentionsUser->execute($this->output);

        $this->validation->execute($this->output);
    }
}
