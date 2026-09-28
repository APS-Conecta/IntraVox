<?php
declare(strict_types=1);

namespace OCA\IntraVox\Tests\Unit\Service\Import;

use OCA\IntraVox\Service\Import\ManagedTreeImporter;
use OCP\Files\File;
use OCP\Files\Folder;
use PHPUnit\Framework\TestCase;

/**
 * The seam's safety net (review L1-08): a re-run of the managed import can add
 * what is missing and must never flatten a page staff edited. Before this class
 * existed the command overwrote every existing JSON with the payload version and
 * the only guard was gestion's whole-tree marker — one missing marker and a
 * reseed silently destroyed editorial work while every gate stayed green.
 *
 * Source trees are real temp directories (the importer reads the host FS); the
 * groupfolder side is recording doubles. Recordings live in test-owned arrays
 * keyed by object id — PHP 8.2+ deprecates dynamic properties on the mocks.
 * Not BuildsFolderFixtures: that harness wires FolderContext seam closures for
 * page-service tests; this test needs raw Folder/File doubles that count writes.
 */
class ManagedTreeImporterTest extends TestCase {
    private string $src;
    /** @var string[] every line the importer said */
    private array $said = [];
    /** @var array<int,int> putContent() calls per File double */
    private array $writes = [];
    /** @var array<int,string> last content written per File double */
    private array $contents = [];
    /** @var array<int,array<string,File|Folder>> nodes created per Folder double, in call order */
    private array $created = [];

    protected function setUp(): void {
        $this->src = sys_get_temp_dir() . '/mti-' . bin2hex(random_bytes(4));
        mkdir($this->src);
        $this->said = [];
        $this->writes = [];
        $this->contents = [];
        $this->created = [];
    }

    protected function tearDown(): void {
        exec('rm -rf ' . escapeshellarg($this->src));
    }

    public function testSkipExistingNeverWritesAnExistingNodeAndStillCreatesTheMissingOnes(): void {
        $this->writeSource([
            'home.json' => '{"uniqueId":"page-aps-1","title":"Inicio"}',
            'noticias/noticias.json' => '{"uniqueId":"page-aps-2","title":"Noticias"}',
            'noticias/images/foto.svg' => '<svg/>',
            'campanas/campanas.json' => '{"uniqueId":"page-aps-3","title":"Campañas"}',
        ]);
        $home = $this->existingFile();
        $noticiasJson = $this->existingFile();
        $foto = $this->existingFile();
        $noticiasImages = $this->folder(['foto.svg' => $foto]);
        $noticias = $this->folder(['noticias.json' => $noticiasJson, 'images' => $noticiasImages]);
        $lang = $this->folder(['home.json' => $home, 'noticias' => $noticias]);

        $report = (new ManagedTreeImporter())->importTree($this->src, $lang, true, $this->recorder());

        foreach ([$home, $noticiasJson, $foto] as $node) {
            $this->assertSame(0, $this->writesOf($node), 'an existing node was overwritten under --skip-existing');
        }
        $this->assertSame([], $this->createdIn($noticias), 'an existing page gained a node it did not have (images/ or otherwise)');
        $this->assertSame(['campanas'], array_keys($this->createdIn($lang)), 'only the missing section is created at the root');
        $campanas = $this->createdIn($lang)['campanas'];
        $this->assertInstanceOf(Folder::class, $campanas);
        $this->assertSame(['images', 'campanas.json'], array_keys($this->createdIn($campanas)));
        $this->assertSame(['created' => 1, 'updated' => 0, 'skipped' => 3, 'errors' => 0], $report);
        $this->assertContains('<comment>Skipped (exists): noticias/noticias.json</comment>', $this->said);
    }

    public function testWithoutTheFlagAnExistingPageIsOverwrittenAsBefore(): void {
        $this->writeSource(['noticias/noticias.json' => '{"uniqueId":"page-aps-2","title":"Noticias v2"}']);
        $noticiasJson = $this->existingFile();
        $noticias = $this->folder(['noticias.json' => $noticiasJson, 'images' => $this->folder([])]);
        $lang = $this->folder(['noticias' => $noticias]);

        $report = (new ManagedTreeImporter())->importTree($this->src, $lang, false, $this->recorder());

        $this->assertSame(1, $this->writesOf($noticiasJson), 'legacy mode must still overwrite');
        $this->assertSame('{"uniqueId":"page-aps-2","title":"Noticias v2"}', $this->contentOf($noticiasJson));
        $this->assertSame(['created' => 0, 'updated' => 1, 'skipped' => 0, 'errors' => 0], $report);
    }

    public function testAPageWithoutUniqueIdIsMintedOneOnTheWayIn(): void {
        $this->writeSource(['aviso/aviso.json' => '{"title":"Aviso"}']);
        $lang = $this->folder([]);

        (new ManagedTreeImporter())->importTree($this->src, $lang, true, $this->recorder());

        $aviso = $this->createdIn($lang)['aviso'];
        $this->assertInstanceOf(Folder::class, $aviso);
        $json = $this->createdIn($aviso)['aviso.json'];
        $this->assertInstanceOf(File::class, $json);
        $written = json_decode($this->contentOf($json), true);
        $this->assertMatchesRegularExpression('/^page-[0-9a-f]{16}$/', $written['uniqueId']);
    }

    public function testAFolderThatDoesNotOwnItsJsonIsNeitherImportedNorRecursedInto(): void {
        $this->writeSource(['_resources/nested/nested.json' => '{"uniqueId":"x"}']);
        $lang = $this->folder([]);

        $report = (new ManagedTreeImporter())->importTree($this->src, $lang, true, $this->recorder());

        $this->assertSame([], $this->createdIn($lang));
        $this->assertSame(0, $report['created'] + $report['updated'] + $report['skipped']);
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    /** @param array<string,string> $files relative path => content */
    private function writeSource(array $files): void {
        foreach ($files as $rel => $content) {
            $path = $this->src . '/' . $rel;
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0777, true);
            }
            file_put_contents($path, $content);
        }
    }

    private function recorder(): callable {
        return function (string $line): void { $this->said[] = $line; };
    }

    private function writesOf(File $file): int {
        return $this->writes[spl_object_id($file)] ?? 0;
    }

    private function contentOf(File $file): string {
        return $this->contents[spl_object_id($file)] ?? '';
    }

    /** @return array<string,File|Folder> */
    private function createdIn(Folder $folder): array {
        return $this->created[spl_object_id($folder)] ?? [];
    }

    /** A File double whose putContent() calls and last content are recorded — the whole "was it overwritten" surface. */
    private function existingFile(): File {
        $file = $this->createMock(File::class);
        $id = spl_object_id($file);
        $file->method('putContent')->willReturnCallback(function ($content) use ($id): void {
            $this->writes[$id] = ($this->writes[$id] ?? 0) + 1;
            $this->contents[$id] = (string)$content;
        });
        return $file;
    }

    /**
     * A Folder double over a name => node map that records what the importer
     * creates in it, in call order, so a test can read the tree it produced.
     * @param array<string,File|Folder> $children
     */
    private function folder(array $children): Folder {
        $folder = $this->createMock(Folder::class);
        $id = spl_object_id($folder);
        $nodes = $children;
        $folder->method('nodeExists')->willReturnCallback(function ($name) use (&$nodes): bool {
            return isset($nodes[$name]);
        });
        $folder->method('get')->willReturnCallback(function ($name) use (&$nodes) {
            return $nodes[$name];
        });
        $folder->method('newFolder')->willReturnCallback(function ($name) use (&$nodes, $id): Folder {
            $child = $this->folder([]);
            $nodes[$name] = $child;
            $this->created[$id][$name] = $child;
            return $child;
        });
        $folder->method('newFile')->willReturnCallback(function ($name, $content) use (&$nodes, $id): File {
            $child = $this->existingFile();
            $this->contents[spl_object_id($child)] = (string)$content;
            $nodes[$name] = $child;
            $this->created[$id][$name] = $child;
            return $child;
        });
        return $folder;
    }
}
