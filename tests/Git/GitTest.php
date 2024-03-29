<?php declare(strict_types=1);

namespace Granam\Tests\Git;

use Granam\Git\Exceptions\CanNotDiffDetachedBranch;
use Granam\Git\Exceptions\CanNotGetGitDiff;
use Granam\Git\Exceptions\ExecutingCommandFailed;
use Granam\Git\Git;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class GitTest extends TestCase
{

    private $temporaryDir;
    private $temporaryOriginDir;

    protected function tearDown(): void
    {
        if ($this->temporaryDir) {
            exec(sprintf('rm -fr %s', escapeshellarg($this->temporaryDir)));
        }
        if ($this->temporaryOriginDir) {
            exec(sprintf('rm -fr %s', escapeshellarg($this->temporaryOriginDir)));
        }
    }

    /**
     * @test
     */
    public function I_can_get_git_status(): void
    {
        self::assertNotEmpty($this->getGit()->getGitStatus(__DIR__), 'Expected some GIT status');
    }

    private function getGit(): Git
    {
        return new Git();
    }

    /**
     * @test
     */
    public function I_can_get_diff_against_origin(): void
    {
        self::assertIsArray($this->getGit()->getDiffAgainstOrigin(__DIR__));
    }

    /**
     * @test
     */
    public function I_am_stopped_if_want_diff_for_detached_branch(): void
    {
        $this->temporaryDir = sys_get_temp_dir() . '/' . uniqid('detached-branch-', true);
        $escapedTestDir = escapeshellarg($this->temporaryDir);
        $this->runCommand(sprintf('mkdir -p %s', $escapedTestDir));
        $this->runCommand(sprintf('git -C %s init', $escapedTestDir));
        $this->runCommand(sprintf('echo foo > %s/foo.txt', $escapedTestDir));
        $this->runCommand(sprintf('git -C %s add . && git -C %s commit -m "Foo"', $escapedTestDir, $escapedTestDir));
        $this->runCommand(sprintf('echo bar > %s/bar.txt', $escapedTestDir));
        $this->runCommand(sprintf('git -C %s add . && git -C %s commit -m "Bar"', $escapedTestDir, $escapedTestDir));
        $this->runCommand(sprintf('git -C %s checkout HEAD^', $escapedTestDir));

        $this->expectException(CanNotDiffDetachedBranch::class);
        $this->expectExceptionMessageMatches(sprintf('~%s~', preg_quote($this->temporaryDir, '~')));
        $this->getGit()->getDiffAgainstOrigin($this->temporaryDir);
    }

    private function runCommand(string $command)
    {
        exec("$command 2>&1", $output, $return);
        self::assertSame(0, $return, sprintf("Failed to run command '%s' with result:\n%s", $command, implode("\n", $output)));
    }

    /**
     * @test
     */
    public function I_am_stopped_if_want_diff_on_git_repository_without_commit(): void
    {
        $this->temporaryDir = sys_get_temp_dir() . '/' . uniqid('no-commit-yet-', true);
        $escapedTestDir = escapeshellarg($this->temporaryDir);
        $this->runCommand(sprintf('mkdir -p %s', $escapedTestDir));
        $this->runCommand(sprintf('git -C %s init', $escapedTestDir));

        $this->expectException(CanNotGetGitDiff::class);
        $this->expectExceptionMessageMatches(sprintf('~%s~', preg_quote($this->temporaryDir, '~')));
        $this->getGit()->getDiffAgainstOrigin($this->temporaryDir);
    }

    /**
     * @test
     */
    public function I_can_get_last_commit(): void
    {
        $expectedLastCommit = trim(file_get_contents(__DIR__ . '/../../.git/refs/heads/master'));
        self::assertSame($expectedLastCommit, $this->getGit()->getLastCommitHash(__DIR__));
    }

    /**
     * @test
     */
    public function I_can_ask_if_remote_branch_exists(): void
    {
        self::assertTrue(
            $this->getGit()->remoteBranchExists('master'),
            'Expected master branch to be detected as existing in remote repository'
        );
        self::assertFalse(
            $this->getGit()->remoteBranchExists('nonsense'),
            "'nonsense' branch is not expected to exists at all"
        );
    }

    /**
     * @test
     */
    public function I_can_get_all_patch_versions(): void
    {
        $this->temporaryDir = sys_get_temp_dir() . '/' . uniqid('testing dir to get Git repo without patch versions ', true);
        if (!@mkdir($this->temporaryDir)) {
            self::fail("Can not create dir '$this->temporaryDir'");
        }
        $gitInit = new Process(['git', 'init'], $this->temporaryDir);
        $exitStatus = $gitInit->run();
        self::assertSame(0, $exitStatus, sprintf('Can not initialize testing Git repository: %s', $gitInit->getErrorOutput()));

        $touch = new Process(['touch', 'first-file'], $this->temporaryDir);
        $exitStatus = $touch->run();
        self::assertSame(0, $exitStatus, sprintf('Can not touch a file in a dir %s: %s', $this->temporaryDir, $touch->getErrorOutput()));

        $gitAdd = new Process(['git', 'add', '.'], $this->temporaryDir);
        $exitStatus = $gitAdd->run();
        self::assertSame(0, $exitStatus, sprintf('Can not add files to a repository: %s', $gitAdd->getErrorOutput()));

        $gitCommit = new Process(['git', 'commit', '--message', 'My first'], $this->temporaryDir);
        $exitStatus = $gitCommit->run();
        self::assertSame(0, $exitStatus, sprintf('Can not commit: %s', $gitCommit->getErrorOutput()));

        $gitNoiseBeforeAndAfterTag = new Process(['git', 'tag', 'noisev21.3.458after'], $this->temporaryDir);
        $exitStatus = $gitNoiseBeforeAndAfterTag->run();
        self::assertSame(0, $exitStatus, sprintf('Can not add a tag: %s', $gitNoiseBeforeAndAfterTag->getErrorOutput()));

        $gitNoiseAfterTag = new Process(['git', 'tag', 'v1.3.458after'], $this->temporaryDir);
        $exitStatus = $gitNoiseAfterTag->run();
        self::assertSame(0, $exitStatus, sprintf('Can not add a tag: %s', $gitNoiseAfterTag->getErrorOutput()));

        $gitNoiseBeforeTag = new Process(['git', 'tag', 'noisev2.3.458'], $this->temporaryDir);
        $exitStatus = $gitNoiseBeforeTag->run();
        self::assertSame(0, $exitStatus, sprintf('Can not add a tag: %s', $gitNoiseBeforeTag->getErrorOutput()));

        $gitPrefixedVersionTag = new Process(['git', 'tag', 'v3.3.458'], $this->temporaryDir);
        $exitStatus = $gitPrefixedVersionTag->run();
        self::assertSame(0, $exitStatus, sprintf('Can not add a tag: %s', $gitPrefixedVersionTag->getErrorOutput()));

        $gitNumericVersionTag = new Process(['git', 'tag', '4.3.458'], $this->temporaryDir);
        $exitStatus = $gitNumericVersionTag->run();
        self::assertSame(0, $exitStatus, sprintf('Can not add a tag: %s', $gitNumericVersionTag->getErrorOutput()));

        self::assertSame(['v3.3.458', '4.3.458'], $this->getGit()->getAllPatchVersions($this->temporaryDir));
    }

    /**
     * @test
     * @dataProvider provideBranchesSourceFlags
     * @param bool $includeLocalBranches
     * @param bool $includeRemoteBranches
     */
    public function I_can_get_all_minor_versions(bool $includeLocalBranches, bool $includeRemoteBranches): void
    {
        $allMinorVersions = $this->getGit()->getAllMinorVersions(__DIR__, $includeLocalBranches, $includeRemoteBranches);
        self::assertContains(
            '1.0',
            $allMinorVersions,
            sprintf('There are only minor versions %s', var_export($allMinorVersions, true))
        );
    }

    public function provideBranchesSourceFlags(): array
    {
        return [
            'both local and remote branches' => [Git::INCLUDE_LOCAL_BRANCHES, Git::INCLUDE_REMOTE_BRANCHES],
            'only local branches' => [Git::INCLUDE_LOCAL_BRANCHES, Git::EXCLUDE_REMOTE_BRANCHES],
            'only remote branches' => [Git::EXCLUDE_LOCAL_BRANCHES, Git::INCLUDE_REMOTE_BRANCHES],
        ];
    }

    /**
     * @test
     */
    public function I_can_not_exclude_both_local_and_remote_branches_when_asking_to_versions(): void
    {
        $this->expectException(\Granam\Git\Exceptions\LocalOrRemoteBranchesShouldBeRequired::class);
        $this->getGit()->getAllMinorVersions(__DIR__, Git::EXCLUDE_LOCAL_BRANCHES, Git::EXCLUDE_REMOTE_BRANCHES);
    }

    /**
     * @test
     */
    public function I_can_get_last_stable_minor_version(): void
    {
        self::assertMatchesRegularExpression(
            '~^v?\d+[.]\d+$~',
            $this->getGit()->getLastStableMinorVersion(__DIR__),
            'Some last stable minor version expected'
        );
    }

    /**
     * @test
     */
    public function I_can_get_last_patch_version_of_minor_version(): void
    {
        self::assertMatchesRegularExpression(
            '~^1[.]0[.]\d+$~',
            $this->getGit()->getLastPatchVersionOf('1.0', __DIR__),
            'Some last patch version to a minor version expected'
        );
    }

    /**
     * @test
     */
    public function I_am_stopped_when_asking_for_last_patch_version_of_non_existing_minor_version(): void
    {
        $this->expectExceptionMessageMatches('~999[.]999~');
        $this->expectException(\Granam\Git\Exceptions\NoPatchVersionsMatch::class);
        $this->getGit()->getLastPatchVersionOf('999.999', __DIR__);
    }

    /**
     * @test
     */
    public function I_can_get_last_patch_version(): void
    {
        self::assertMatchesRegularExpression(
            '~^v?(\d+[.]){2}\d+$~',
            $this->getGit()->getLastPatchVersion(__DIR__),
            'Some last patch version expected'
        );
    }

    /**
     * @test
     */
    public function I_can_get_current_branch_name(): void
    {
        self::assertMatchesRegularExpression('~^(master|v?\d+[.]\d+)$~', $this->getGit()->getCurrentBranchName(__DIR__));
    }

    /**
     * @test
     */
    public function It_can_detect_lock()
    {
        $tempDir = sys_get_temp_dir();
        $this->temporaryDir = $tempDir . '/' . uniqid('git_lock_test', true);
        $this->temporaryOriginDir = $tempDir . '/' . uniqid('git_origin_lock_test', true);
        $tempGitOriginDirEscaped = escapeshellarg($this->temporaryOriginDir);
        $tempGitDirEscaped = escapeshellarg($this->temporaryDir);
        $commands = [
            "mkdir $tempGitDirEscaped",
            "cd $tempGitDirEscaped",
            "git init",
            "touch foo",
            "git add foo",
            "git commit -m 'bar'",
            "cp -r $tempGitDirEscaped $tempGitOriginDirEscaped",
            "git remote add origin $tempGitOriginDirEscaped",
            "git fetch",
            "git branch --set-upstream-to=origin/master master",
            "cd $tempGitOriginDirEscaped",
            "touch baz",
            "git add baz",
            "git commit -m 'qux'",
        ];
        $command = implode(' 2>&1 && ', $commands) . ' 2>&1';
        exec(
            $command,
            $output,
            $returnCode
        );
        self::assertSame(0, $returnCode, "Failed command $command: " . implode("\n", $output));

        $commands = [
            "touch $tempGitDirEscaped/.git/refs/remotes/origin/master.lock",
        ];
        $command = implode(' 2>&1 && ', $commands) . ' 2>&1';
        exec(
            $command,
            $output,
            $returnCode
        );
        self::assertSame(0, $returnCode, "Failed command $command: " . implode("\n", $output));

        $git = new Git(0 /* instant "sleep" */);
        try {
            $git->update($this->temporaryDir);
        } catch (ExecutingCommandFailed $executingCommandFailed) {
            unlink("$this->temporaryDir/.git/refs/remotes/origin/master.lock");
            $git->update($this->temporaryDir);
        }
    }

    /**
     * @test
     * @dataProvider provideRestorableError
     * @param string $errorMessage
     */
    public function It_will_try_to_restore_from_restorable_errors(string $errorMessage)
    {
        $maxAttempts = 5;
        $git = new class($errorMessage, $maxAttempts) extends Git {
            private $errorMessage;
            private $throwOnAttemptsLesserThan;
            private $attempt = 0;

            public function __construct(string $errorMessage, int $throwOnAttemptsLesserThan)
            {
                parent::__construct(0 /* no sleep */);
                $this->errorMessage = $errorMessage;
                $this->throwOnAttemptsLesserThan = $throwOnAttemptsLesserThan;
            }

            protected function executeArray(string $command, bool $sendErrorsToStdOut = true, bool $solveMissingHomeDir = true): array
            {
                $this->attempt++;
                if ($this->attempt < $this->throwOnAttemptsLesserThan) {
                    throw new ExecutingCommandFailed($this->errorMessage);
                }
                return [$command];
            }
        };
        $output = $git->update('foo', $maxAttempts);
        self::assertStringContainsString("attempt number $maxAttempts", $output[0]);
    }

    public function provideRestorableError()
    {
        return [
            [<<<TEXT
From gitlab.com:drdplusinfo/bestiar
   7e73a42..606b6c3  1.0        -> origin/1.0
   7e73a42..606b6c3  master     -> origin/master
 * [new tag]         1.0.11     -> 1.0.11
Updating 7e73a42..606b6c3
Fast-forward
 composer.json                                      |  6 --
 composer.lock                                      | 26 +++---
 .../generic/skeleton/rules-images.css              |  0
 css/generic/skeleton/rules-main.css                | 18 ++++-
 vendor/composer/installed.json                     | 28 ++++---
 .../DrdPlus/RulesSkeleton/HomepageDetector.php     | 10 ++-
 .../DrdPlus/RulesSkeleton/RulesUrlMatcher.php      |  1 +
 .../DrdPlus/RulesSkeleton/Web/content/menu.php     |  4 +-
 .../DrdPlus/Tests/RulesSkeleton/AnchorsTest.php    | 80 ++++++------------
 .../Tests/RulesSkeleton/CalculationsTest.php       | 94 ++++++++++++++++++----
 .../Tests/RulesSkeleton/ComposerConfigTest.php     | 35 +++++++-
 .../Tests/RulesSkeleton/CurrentWebVersionTest.php  | 10 ++-
 .../Tests/RulesSkeleton/HomepageDetectorTest.php   | 34 ++++++++
 .../DrdPlus/Tests/RulesSkeleton/HtmlHelperTest.php | 15 +++-
 .../Partials/TestsConfigurationReader.php          |  2 +
 .../DrdPlus/Tests/RulesSkeleton/PassingTest.php    |  9 ++-
 .../Tests/RulesSkeleton/StandardModeTest.php       | 12 ++-
 .../Tests/RulesSkeleton/TableOfContentsTest.php    | 12 ++-
 .../Tests/RulesSkeleton/TestsConfiguration.php     | 14 ++++
 .../Tests/RulesSkeleton/TestsConfigurationTest.php |  3 +-
 .../DrdPlus/Tests/RulesSkeleton/Web/MenuTest.php   | 39 +++++++++
 .../RulesSkeleton/Web/RulesMainContentTest.php     | 40 +++++++--
 .../css/generic/skeleton/rules-images.css          |  0
 .../css/generic/skeleton/rules-main.css            | 18 ++++-
 vendor/drdplus/rules-skeleton/web/04 headings.html |  7 +-
 .../drdplus/rules-skeleton/web/24 calculation.html | 31 ++++++-
 .../Tests/WebContentBuilder/HtmlHelperTest.php     | 73 ++++++++++++++++-
 .../Granam/WebContentBuilder/Dirs.php              | 13 +--
 .../Granam/WebContentBuilder/HtmlHelper.php        | 70 ++++++++++++----
 29 files changed, 559 insertions(+), 145 deletions(-)
 rename vendor/drdplus/rules-skeleton/css/generic/skeleton/rulesimages.css => css/generic/skeleton/rules-images.css (100%)
 rename css/generic/skeleton/rulesimages.css => vendor/drdplus/rules-skeleton/css/generic/skeleton/rules-images.css (100%)
It doesn't make sense to pull all tags; you probably meant:
  git fetch --tags
TEXT
                ,
                <<<TEXT
attempt number 1
error: Ref refs/remotes/origin/1.0 is at 606b6c32a03cfd02982dd55b413a2cf01f53a175 but expected 7e73a42f1593894e5ebb0f636010df2461dc9ce6
From gitlab.com:drdplusinfo/bestiar
 ! 7e73a42..606b6c3  1.0        -> origin/1.0  (unable to update local ref)
error: Ref refs/remotes/origin/master is at 606b6c32a03cfd02982dd55b413a2cf01f53a175 but expected 7e73a42f1593894e5ebb0f636010df2461dc9ce6
 ! 7e73a42..606b6c3  master     -> origin/master  (unable to update local ref)
 * [new tag]         1.0.11     -> 1.0.11
TEXT
                ,
            ],
        ];
    }
}
