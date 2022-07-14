<?php

namespace Packrats\ImportFluxBB\Importer;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use PDO;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;

class Categories
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
        $output->writeln('Importing categories...');

        $sql = sprintf(
            "SELECT `id`, `cat_name`, `disp_position` FROM %s ORDER BY `id`",
            $this->fluxBBPrefix .'categories'
        );
        $stmt = $this->fluxBBDatabase->query($sql);
        $categories = $stmt->fetchAll(PDO::FETCH_OBJ);

        $progressBar = new ProgressBar($output, count($categories));

        $this->database->statement('SET FOREIGN_KEY_CHECKS=0');
        foreach ($categories as $category) {
            $this->database
                ->table('tags')
                ->insert(
                    [
                        'id' => $category->id,
                        'name' => $category->cat_name,
                        'slug' => Str::slug(preg_replace('/\.+/', '-', $category->cat_name), '-', 'de'),
                        'position' => $category->disp_position,
                        'color' => '#08c',
                        'discussion_count' => $this->getNumberOfTopics($category->id),
                        'last_posted_at' => (new \DateTime())->setTimestamp($this->getLastPostedAt($category->id)),
                        'last_posted_discussion_id' => $this->getLastTopicId($category->id),
                        'last_posted_user_id' => $this->getLastPostUserId($category->id),
                    ]
                );
            $progressBar->advance();
        }
        $this->database->statement('SET FOREIGN_KEY_CHECKS=1');
        $progressBar->finish();

        $output->writeln('');
    }

    private function getNumberOfTopics(int $categoryId): int
    {
        $sql = sprintf(
            "SELECT SUM(`num_topics`) FROM %s WHERE `cat_id = :categoryId`",
            $this->fluxBBPrefix .'forums'
        );
        $stmt = $this->fluxBBDatabase->prepare($sql);
        $stmt->bindValue('categoryId', $categoryId, PDO::PARAM_INT);
        $stmt->execute();

        return (int)$stmt->fetchColumn();
    }

    private function getLastPostId(int $categoryId): int
    {
        $sql = sprintf(
            "SELECT `last_post_id` FROM %s WHERE `cat_id` = :categoryId ORDER BY `last_post` DESC",
            $this->fluxBBPrefix .'forums'
        );
        $stmt = $this->fluxBBDatabase->prepare($sql);
        $stmt->bindValue('categoryId', $categoryId, PDO::PARAM_INT);
        $stmt->execute();

        return (int)$stmt->fetchColumn();
    }

    private function getLastPostedAt(int $categoryId): int
    {
        $sql = sprintf(
            "SELECT `last_post` FROM %s WHERE `cat_id` = :categoryId ORDER BY `last_post` DESC",
            $this->fluxBBPrefix .'forums'
        );
        $stmt = $this->fluxBBDatabase->prepare($sql);
        $stmt->bindValue('categoryId', $categoryId, PDO::PARAM_INT);
        $stmt->execute();

        return (int)$stmt->fetchColumn();
    }

    private function getLastTopicId(int $categoryId): int
    {
        $lastPostId = $this->getLastPostId($categoryId);

        $sql = sprintf(
            "SELECT `topic_id` FROM %s WHERE `id` = :lastPostId",
            $this->fluxBBPrefix .'posts'
        );
        $stmt = $this->fluxBBDatabase->prepare($sql);
        $stmt->bindValue('lastPostId', $lastPostId, PDO::PARAM_INT);
        $stmt->execute();

        return (int)$stmt->fetchColumn();
    }

    private function getLastPostUserId(int $categoryId): ?int
    {
        $lastPostId = $this->getLastPostId($categoryId);

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
