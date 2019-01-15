<?php
declare(strict_types=1);

namespace Granam\Tests\Git;

use Granam\Git\Git;
use PHPUnit\Framework\TestCase;

class GitTest extends TestCase
{
    /**
     * @test
     */
    public function I_can_get_git_status(): void
    {
        self::assertNotEmpty($this->getGit()->getGitStatus(), 'Expected some GIT status');
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
        self::assertIsArray($this->getGit()->getDiffAgainstOriginMaster());
    }

    /**
     * @test
     */
    public function I_can_get_last_commit(): void
    {
        $expectedLastCommit = \trim(\file_get_contents(__DIR__ . '/../../../.git/refs/heads/master'));
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
    public function I_can_get_tag_patch_versions(): void
    {
        self::assertNotEmpty($this->getGit()->getTagPatchVersions(__DIR__));
    }

    /**
     * @test
     * @dataProvider provideBranchesSourceFlags
     * @param bool $includeLocalBranches
     * @param bool $includeRemoteBranches
     */
    public function I_can_get_all_minor_version_like_branches(bool $includeLocalBranches, bool $includeRemoteBranches): void
    {
        self::assertContains(
            '1.0',
            $this->getGit()->getAllMinorVersionLikeBranches(__DIR__, $includeLocalBranches, $includeRemoteBranches)
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
     * @expectedException \Granam\Git\Exceptions\LocalOrRemoteBranchesShouldBeRequired
     */
    public function I_can_not_exclude_both_local_and_remote_branches_when_asking_to_version_likes(): void
    {
        $this->getGit()->getAllMinorVersionLikeBranches(__DIR__, Git::EXCLUDE_LOCAL_BRANCHES, Git::EXCLUDE_REMOTE_BRANCHES);
    }
}