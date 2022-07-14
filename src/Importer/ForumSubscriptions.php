<?php

namespace Packrats\ImportFluxBB\Importer;

use Illuminate\Database\ConnectionInterface;
use PDO;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;

class ForumSubscriptions
{
    /**
     * @var ConnectionInterface
     */
    private $database;
    /**
     * @var PDO
     */
    private $fluxBBDatabase;
    /**
     * @var string
     */
    private $fluxBBPrefix;

    public function __construct(ConnectionInterface $database)
    {
        $this->database = $database;
    }

    public function execute(OutputInterface $output, PDO $fluxBBDatabase, string $fluxBBPrefix)
    {
        $this->fluxBBDatabase = $fluxBBDatabase;
        $this->fluxBBPrefix = $fluxBBPrefix;
        $output->writeln('Importing forum_subscriptions...');

        $sql = sprintf(
            "SELECT `user_id`, `forum_id` FROM %s ORDER BY `forum_id`",
            $this->fluxBBPrefix .'forum_subscriptions'
        );
        $stmt = $this->fluxBBDatabase->query($sql);
        $forumSubscriptions = $stmt->fetchAll(PDO::FETCH_OBJ);

        $progressBar = new ProgressBar($output, count($forumSubscriptions));

        foreach ($forumSubscriptions as $topicSubscription) {
            $this->database
                ->table('tag_user')
                ->insert(
                    [
                        'user_id' => $topicSubscription->user_id,
                        'tag_id' => $topicSubscription->forum_id,
                        'marked_as_read_at' => null,
                        'is_hidden' => 0
                    ]
                );
            $progressBar->advance();
        }
        $progressBar->finish();

        $output->writeln('');
    }
}
