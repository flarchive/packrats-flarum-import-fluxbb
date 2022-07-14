<?php

namespace Packrats\ImportFluxBB\Importer;

use Illuminate\Database\ConnectionInterface;
use PDO;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;

class Reports
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
        $output->writeln('Importing reports...');

        $fields = [
            'id',
            'post_id',
            'topic_id',
            'forum_id',
            'reported_by',
            'created',
            'message',
            'zapped',
            'zapped_by'
        ];
        $sql = sprintf(
            "SELECT %s FROM %s WHERE `post_id` != 0 AND `post_id` IN (SELECT id FROM %s) ORDER BY `id`",
            implode(', ', $fields),
            $this->fluxBBPrefix .'reports',
            $this->fluxBBPrefix .'posts'
        );
        $stmt = $this->fluxBBDatabase->query($sql);
        $reports = $stmt->fetchAll(PDO::FETCH_OBJ);

        $progressBar = new ProgressBar($output, count($reports));

        foreach ($reports as $report) {
            $this->database
                ->table('flags')
                ->insert(
                    [
                        'id' => $report->id,
                        'post_id' => $report->post_id,
                        'type' => 'user',
                        'user_id' => $report->reported_by,
                        'reason' => null,
                        'reason_detail' => $report->message,
                        'created_at' => (new \DateTime())->setTimestamp($report->created)
                    ]
                );
            $progressBar->advance();
        }
        $progressBar->finish();

        $output->writeln('');
    }
}
