<?php

namespace Packrats\ImportFluxBB\Importer;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use PDO;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;

class Forums
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
        $output->writeln('Importing forums...');

        $fields = [
            'id',
            'forum_name',
            'forum_desc',
            'redirect_url',
            'moderators',
            'num_topics',
            'num_posts',
            'last_post',
            'last_post_id',
            'last_poster',
            'sort_by',
            'disp_position',
            'cat_id'
        ];
        $sql = sprintf(
            "SELECT %s FROM %s ORDER BY `id`",
            implode(', ', $fields),
            $this->fluxBBPrefix .'forums'
        );
        $stmt = $this->fluxBBDatabase->query($sql);
        $forums = $stmt->fetchAll(PDO::FETCH_OBJ);

        $progressBar = new ProgressBar($output, count($forums));

        $this->database->statement('SET FOREIGN_KEY_CHECKS=0');
        foreach ($forums as $forum) {
            $this->database
                ->table('tags')
                ->insert(
                    [
                        'id' => $forum->id,
                        'name' => $forum->forum_name,
                        'slug' => Str::slug(preg_replace('/\.+/', '-', $forum->forum_name), '-', 'de'),
                        'description' => $forum->forum_desc,
                        'position' => $forum->disp_position,
                        'parent_id' => $forum->cat_id,
                        'discussion_count' => $forum->num_topics,
                        'last_posted_at' => (new \DateTime())->setTimestamp($forum->last_post),
                        'last_posted_discussion_id' => $this->getLastTopicId($forum->last_post_id),
                        'last_posted_user_id' => $this->getLastPostUserId($forum->last_post_id),
                        'color' => '#333'
                    ]
                );
            $progressBar->advance();
        }
        $this->database->statement('SET FOREIGN_KEY_CHECKS=1');
        $progressBar->finish();

        $output->writeln('');
    }

    private function getLastTopicId(int $lastPostId): ?int
    {
        $sql = sprintf(
            "SELECT `topic_id` FROM %s WHERE `id` = :lastPostId",
            $this->fluxBBPrefix .'posts'
        );
        $stmt = $this->fluxBBDatabase->prepare($sql);
        $stmt->bindValue('lastPostId', $lastPostId, PDO::PARAM_INT);
        $stmt->execute();

        $topicId = $stmt->fetchColumn();

        return $topicId ? (int)$topicId : null;
    }

    private function getLastPostUserId(int $lastPostId): ?int
    {
        $sql = sprintf(
            "SELECT `poster_id` FROM %s WHERE `id` = :lastPostId AND `poster_id` != 1",
            $this->fluxBBPrefix .'posts'
        );
        $stmt = $this->fluxBBDatabase->prepare($sql);
        $stmt->bindValue('lastPostId', $lastPostId, PDO::PARAM_INT);
        $stmt->execute();

        $posterId = $stmt->fetchColumn();

        return $posterId ? (int)$posterId : null;
    }
}
