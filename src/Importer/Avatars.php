<?php

namespace Packrats\ImportFluxBB\Importer;

use Flarum\Foundation\Paths;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Intervention\Image\ImageManagerStatic as Image;
use PDO;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;

class Avatars
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
    /**
     * @var string
     */
    private $fluxBBHost;
    /**
     * @var ContainerInterface
     */
    private $container;

    public function __construct(ConnectionInterface $database, ContainerInterface $container)
    {
        $this->database = $database;
        $this->container = $container;
    }

    public function execute(OutputInterface $output, PDO $fluxBBDatabase, string $fluxBBPrefix, string $fluxBBHost)
    {
        $this->fluxBBDatabase = $fluxBBDatabase;
        $this->fluxBBPrefix = $fluxBBPrefix;
        $this->fluxBBHost = $fluxBBHost;
        $output->writeln('Importing avatars...');

        $sql = sprintf(
            "SELECT `id` FROM %s WHERE `username` != 'Guest' ORDER BY `id`",
            $this->fluxBBPrefix .'users'
        );
        $stmt = $this->fluxBBDatabase->query($sql);
        $users = $stmt->fetchAll(PDO::FETCH_OBJ);

        $progressBar = new ProgressBar($output, count($users));

        foreach ($users as $user) {
            $this->database
                ->table('users')
                ->where('id', '=', $user->id)
                ->update(['avatar_url' => $this->createAvatarUrl($user->id)]);
            $progressBar->advance();
        }
        $progressBar->finish();

        $output->writeln('');
    }

    /**
     * @param int $userId
     * @return string|null
     */
    private function createAvatarUrl(int $userId): ?string
    {
        $avatarGif = file_get_contents(sprintf('%s/forum/img/avatars/%d.gif', $this->fluxBBHost, $userId));
        $avatarJpg = file_get_contents(sprintf('%s/forum/img/avatars/%d.jpg', $this->fluxBBHost, $userId));
        $avatarPng = file_get_contents(sprintf('%s/forum/img/avatars/%d.png', $this->fluxBBHost, $userId));

        if ($avatarGif !== false) {
            $avatarFile = $avatarGif;
        } elseif ($avatarJpg !== false) {
            $avatarFile = $avatarJpg;
        } elseif ($avatarPng !== false) {
            $avatarFile = $avatarPng;
        } else {
            return null;
        }

        $newFileName = Str::random() . '.png';
        $newDir = $this->container[Paths::class]->public . '/assets/avatars';
		if (!is_dir($newDir)) {
			mkdir($newDir);
		}
        $newPath = $newDir . '/' . $newFileName;
        if (file_exists($newPath)) {
            throw new RuntimeException('Avatar already exists: ' . $newFileName);
        }

        Image::configure(['driver' => 'imagick']);
        $image = Image::make($avatarFile);
        if (!Str::endsWith($avatarFile, '.png')
            || $image->getWidth() !== $image->getHeight()
            || $image->getWidth() > 100) {
            $newSize = max($image->getWidth(), $image->getHeight());
            if ($newSize > 100) {
                $newSize = 100;
            }
            $encodedImage = $image->orientate()->fit($newSize, $newSize)->encode('png');
            file_put_contents($newPath, $encodedImage);
        } else {
            file_put_contents($avatarFile, $newPath);
        }
        system('optipng -o 5 -strip all -snip -quiet ' . $newPath);

        return $newFileName;
    }
}
