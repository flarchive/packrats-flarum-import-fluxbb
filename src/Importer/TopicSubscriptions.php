<?php

namespace Packrats\ImportFluxBB\Importer;

use Illuminate\Database\ConnectionInterface;
use PDO;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;

class TopicSubscriptions
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
        $output->writeln('Importing topic_subscriptions...');

        $sql = sprintf(
            "SELECT `user_id`, `topic_id` FROM %s ORDER BY `topic_id`",
            $this->fluxBBPrefix .'topic_subscriptions'
        );
        $stmt = $this->fluxBBDatabase->query($sql);
        $topicSubscriptions = $stmt->fetchAll(PDO::FETCH_OBJ);

        $progressBar = new ProgressBar($output, count($topicSubscriptions));

        foreach ($topicSubscriptions as $topicSubscription) {
            $this->database
                ->table('discussion_user')
                ->insert(
                    [
                        'user_id' => $topicSubscription->user_id,
                        'discussion_id' => $topicSubscription->topic_id,
                        'last_read_at' => null,
                        'last_read_post_number' => null,
                        'subscription' => 'follow'
                    ]
                );
            $progressBar->advance();
        }
        $progressBar->finish();

        $output->writeln('');
    }
}
