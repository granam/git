<?php
declare(strict_types=1);

namespace Granam\Git;

use Granam\Strict\Object\StrictObject;

class Git extends StrictObject
{
    public const INCLUDE_LOCAL_BRANCHES = true;
    public const EXCLUDE_LOCAL_BRANCHES = false;
    public const INCLUDE_REMOTE_BRANCHES = true;
    public const EXCLUDE_REMOTE_BRANCHES = false;

    /**
     * @param string $dir
     * @return array|string[] Rows with GIT status
     * @throws \Granam\Git\Exceptions\CanNotGetGitStatus
     */
    public function getGitStatus(string $dir): array
    {
        // GIT status is same for any working dir, if it is a sub-dir of wanted GIT project root
        try {
            $escapedDir = \escapeshellarg($dir);

            return $this->executeArray("git -C $escapedDir status");
        } catch (Exceptions\ExecutingCommandFailed $executingCommandFailed) {
            throw new Exceptions\CanNotGetGitStatus(
                "Can not get git status from dir $dir:\n"
                . $executingCommandFailed->getMessage(),
                $executingCommandFailed->getCode(),
                $executingCommandFailed
            );
        }
    }

    /**
     * @param string $dir
     * @return array|string[] Rows with differences
     * @throws \Granam\Git\Exceptions\CanNotGetGitDiff
     */
    public function getDiffAgainstOriginMaster(string $dir): array
    {
        try {
            $escapedDir = \escapeshellarg($dir);

            return $this->executeArray("git -C $escapedDir diff origin/master");
        } catch (Exceptions\ExecutingCommandFailed $executingCommandFailed) {
            throw new Exceptions\CanNotGetGitDiff(
                "Can not get diff:\n"
                . $executingCommandFailed->getMessage(),
                $executingCommandFailed->getCode(),
                $executingCommandFailed
            );
        }
    }

    /**
     * @param string $dir
     * @return string Last commit hash
     * @throws \Granam\Git\Exceptions\ExecutingCommandFailed
     */
    public function getLastCommitHash(string $dir): string
    {
        $escapedDir = \escapeshellarg($dir);

        return $this->execute("git -C $escapedDir log --max-count=1 --format=%H --no-abbrev-commit");
    }

    /**
     * @param string $branch
     * @param string $destinationDir
     * @param string $repositoryUrl
     * @return array|string[] Rows with result of branch clone
     * @throws \Granam\Git\Exceptions\CanNotLocallyCloneWebVersionViaGit
     * @throws \Granam\Git\Exceptions\UnknownMinorVersion
     */
    public function cloneBranch(string $branch, string $repositoryUrl, string $destinationDir): array
    {
        $destinationDirEscaped = \escapeshellarg($destinationDir);
        $branchEscaped = \escapeshellarg($branch);
        try {
            return $this->executeArray("git clone --branch $branchEscaped $repositoryUrl $destinationDirEscaped");
        } catch (Exceptions\ExecutingCommandFailed $executingCommandFailed) {
            if ($this->remoteBranchExists($branch)) {
                throw new Exceptions\CanNotLocallyCloneWebVersionViaGit(
                    "Can not git clone required version '{$branch}':\n"
                    . $executingCommandFailed->getMessage(),
                    $executingCommandFailed->getCode(),
                    $executingCommandFailed
                );
            }
            throw new Exceptions\UnknownMinorVersion(
                "Required minor version $branch as a GIT branch does not exists:\n"
                . $executingCommandFailed->getMessage(),
                $executingCommandFailed->getCode(),
                $executingCommandFailed
            );
        }
    }

    /**
     * @param string $branch
     * @param string $dir
     * @return array|string[] Rows with result of branch update
     * @throws \Granam\Git\Exceptions\CanNotLocallyCloneWebVersionViaGit
     * @throws \Granam\Git\Exceptions\UnknownMinorVersion
     */
    public function updateBranch(string $branch, string $dir): array
    {
        $branchEscaped = \escapeshellarg($branch);
        $dirEscaped = \escapeshellarg($dir);
        $commands = [];
        $commands[] = "cd $dirEscaped";
        $commands[] = "git checkout $branchEscaped";
        $commands[] = 'git pull --ff-only';
        $commands[] = 'git pull --tags';
        $commands[] = 'git checkout -';

        return $this->executeCommandsChainArray($commands);
    }

    /**
     * @param string $branchName
     * @return bool
     * @throws \Granam\Git\Exceptions\CanNotFindOutRemoteBranches
     */
    public function remoteBranchExists(string $branchName): bool
    {
        try {
            $rows = $this->executeArray('git branch --remotes');
        } catch (Exceptions\ExecutingCommandFailed $executingCommandFailed) {
            throw new Exceptions\CanNotFindOutRemoteBranches(
                $executingCommandFailed->getMessage(),
                $executingCommandFailed->getCode(),
                $executingCommandFailed
            );
        }
        foreach ($rows as $remoteBranch) {
            $branchFromRemote = \trim(\explode('/', $remoteBranch)[1] ?? '');
            if ($branchName === $branchFromRemote) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $dir
     * @return array|string[] List of tags with patch versions like 1.12.321
     * @throws \Granam\Git\Exceptions\ExecutingCommandFailed
     */
    public function getPatchVersions(string $dir): array
    {
        $dirEscaped = \escapeshellarg($dir);
        $commands = [
            "git -C $dirEscaped tag",
            'grep -E "v?([[:digit:]]+[.]){2}[[:alnum:]]+([.][[:digit:]]+)?" --only-matching',
            'sort --version-sort --reverse',
        ];

        return $this->executeArray(\implode(' | ', $commands));
    }

    /**
     * @param string $dir
     * @param bool $readLocal
     * @param bool $readRemote
     * @return array|string[] List of branches with minor versions like 1.13, 1.12, sorted from newest to oldest
     * @throws \Granam\Git\Exceptions\LocalOrRemoteBranchesShouldBeRequired
     * @throws \Granam\Git\Exceptions\ExecutingCommandFailed
     */
    public function getAllMinorVersions(
        string $dir,
        bool $readLocal = self::INCLUDE_LOCAL_BRANCHES,
        bool $readRemote = self::INCLUDE_REMOTE_BRANCHES
    ): array
    {
        if (!$readLocal && !$readRemote) {
            throw new Exceptions\LocalOrRemoteBranchesShouldBeRequired(
                'Excluding both local and remote version-like branches has no sense'
            );
        }
        $dirEscaped = \escapeshellarg($dir);
        $branchesCommandParts = [];
        if ($readLocal) {
            $branchesCommandParts[] = "git -C $dirEscaped branch";
        }
        if ($readRemote) {
            $branchesCommandParts[] = "git -C $dirEscaped branch -r";
        }
        $branchesCommand = sprintf('branches=$(%s) && echo $branches', implode(' && ', $branchesCommandParts));
        $commandParts = [
            $branchesCommand,
            'cut -d "/" -f2',
            'grep HEAD --invert-match',
            'grep -P "v?\d+\.\d+" --only-matching',
            'uniq',
            'sort --version-sort --reverse',
        ];

        return $this->executeArray(\implode(' | ', $commandParts));
    }

    public function getLastStableMinorVersion(
        string $dir,
        bool $readLocal = self::INCLUDE_LOCAL_BRANCHES,
        bool $readRemote = self::INCLUDE_REMOTE_BRANCHES
    ): ?string
    {
        return $this->getAllMinorVersions($dir, $readLocal, $readRemote)[0] ?? null;
    }

    /**
     * @param string $superiorVersion
     * @param string $dir
     * @return string
     * @throws \Granam\Git\Exceptions\NoPatchVersionsMatch
     * @throws \Granam\Git\Exceptions\ExecutingCommandFailed
     */
    public function getLastPatchVersionOf(string $superiorVersion, string $dir): string
    {
        $patchVersions = $this->getPatchVersions($dir);
        $matchingPatchVersions = [];
        foreach ($patchVersions as $patchVersion) {
            if (\strpos($patchVersion, $superiorVersion) === 0) {
                $matchingPatchVersions[] = $patchVersion;
            }
        }
        if (!$matchingPatchVersions) {
            throw new Exceptions\NoPatchVersionsMatch(
                sprintf(
                    'No patch version matches given superior version %s, %s',
                    $superiorVersion,
                    $patchVersions
                        ? 'available are only' . \implode(',', $patchVersions)
                        : 'because there are no patch versions at all'
                )
            );
        }
        \usort($matchingPatchVersions, 'version_compare');

        return \end($matchingPatchVersions);
    }

    /**
     * @param string $repositoryDir
     * @return string
     * @throws \Granam\Git\Exceptions\NoPatchVersionsMatch
     * @throws \Granam\Git\Exceptions\ExecutingCommandFailed
     */
    public function getLastPatchVersion(string $repositoryDir): string
    {
        return $this->getLastPatchVersionOf($this->getLastStableMinorVersion($repositoryDir), $repositoryDir);
    }

    /**
     * @param string $command
     * @param bool $sendErrorsToStdOut = true
     * @param bool $solveMissingHomeDir = true
     * @return string[]|array
     * @throws \Granam\Git\Exceptions\ExecutingCommandFailed
     */
    private function executeArray(string $command, bool $sendErrorsToStdOut = true, bool $solveMissingHomeDir = true): array
    {
        if ($sendErrorsToStdOut) {
            $command .= ' 2>&1';
        }
        if ($solveMissingHomeDir) {
            $homeDir = \exec('echo $HOME 2>&1', $output, $returnCode);
            $this->guardCommandWithoutError($returnCode, $command, $output);
            if (!$homeDir) {
                if (\file_exists('/home/www-data')) {
                    $command = 'export HOME=/home/www-data 2>&1 && ' . $command;
                } elseif (\file_exists('/var/www')) {
                    $command = 'export HOME=/var/www 2>&1 && ' . $command;
                } // else we will hope it will somehow pass without fatal: failed to expand user dir in: '~/.gitignore'
            }
        }
        $returnCode = 0;
        $output = [];
        \exec($command, $output, $returnCode);
        $this->guardCommandWithoutError($returnCode, $command, $output);

        return $output;
    }

    /**
     * @param int $returnCode
     * @param string $command
     * @param array $output
     * @throws \Granam\Git\Exceptions\ExecutingCommandFailed
     */
    private function guardCommandWithoutError(int $returnCode, string $command, ?array $output): void
    {
        if ($returnCode !== 0) {
            throw new Exceptions\ExecutingCommandFailed(
                "Error while executing '$command', expected return '0', got '$returnCode'"
                . ($output !== null ?
                    ("with output: '" . \implode("\n", $output) . "'")
                    : ''
                ),
                $returnCode
            );
        }
    }

    /**
     * @param string $command
     * @param bool $sendErrorsToStdOut = true
     * @param bool $solveMissingHomeDir = true
     * @return string
     * @throws \Granam\Git\Exceptions\ExecutingCommandFailed
     */
    private function execute(string $command, bool $sendErrorsToStdOut = true, bool $solveMissingHomeDir = true): string
    {
        $rows = $this->executeArray($command, $sendErrorsToStdOut, $solveMissingHomeDir);

        return \end($rows);
    }

    /**
     * @param array $commands
     * @return array|string[]
     * @throws \Granam\Git\Exceptions\ExecutingCommandFailed
     */
    private function executeCommandsChainArray(array $commands): array
    {
        return $this->executeArray($this->getChainedCommands($commands), false);
    }

    /**
     * @param array $commands
     * @return string
     */
    private function getChainedCommands(array $commands): string
    {
        foreach ($commands as &$command) {
            $command .= ' 2>&1';
        }

        return \implode(' && ', $commands);
    }

}