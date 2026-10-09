<?php

namespace Klyp\WordPressCoreInstaller;

use Composer\Installer\LibraryInstaller;
use Composer\Package\PackageInterface;
use Composer\Repository\InstalledRepositoryInterface;

/**
 * Installs packages of type "wordpress-core" outside the vendor directory.
 *
 * The target directory comes from the root package's
 * extra.wordpress-install-dir, either as a string:
 *
 *     "extra": { "wordpress-install-dir": "wp" }
 *
 * or as a map keyed by package name:
 *
 *     "extra": { "wordpress-install-dir": { "klyp/wordpress": "wp" } }
 *
 * and defaults to "wordpress".
 *
 * Composer deletes the install directory on update and uninstall, so any
 * directory that would take other files with it (the project root, anything
 * outside the project, the vendor directory or a directory containing it)
 * is rejected, as are URLs and stream wrappers ("file://...") and two
 * packages whose directories overlap. A directory that already holds files
 * other than WordPress core is refused too, because Composer would empty it.
 */
class Installer extends LibraryInstaller
{
    const PACKAGE_TYPE = 'wordpress-core';
    const EXTRA_KEY = 'wordpress-install-dir';
    const DEFAULT_DIR = 'wordpress';

    /**
     * Install paths claimed in this run, keyed by package name.
     *
     * @var array<string, string>
     */
    private $claimed = array();

    /**
     * Packages uninstalled in this run, which may still be in the repository,
     * keyed by package name: [install path, removal promise or null].
     *
     * @var array<string, array>
     */
    private $leaving = array();

    public function supports($packageType)
    {
        return $packageType === self::PACKAGE_TYPE;
    }

    public function install(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        $this->claim($repo, $package);
        $this->waitForRemovals($this->getInstallPath($package));
        $this->assertReplaceable($package);

        return parent::install($repo, $package);
    }

    public function update(InstalledRepositoryInterface $repo, PackageInterface $initial, PackageInterface $target)
    {
        $this->claim($repo, $target);
        $this->assertReplaceable($target);

        return parent::update($repo, $initial, $target);
    }

    public function uninstall(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        $path = $this->getInstallPath($package);
        unset($this->claimed[$package->getName()]);
        $this->leaving[$package->getName()] = array($path, null);

        $promise = parent::uninstall($repo, $package);
        $this->leaving[$package->getName()] = array($path, $promise);

        return $promise;
    }

    /**
     * Composer empties the install directory before extracting core into it.
     * That's fine for a previous WordPress install, including a damaged one
     * that's missing files, but a non-empty directory without WordPress in it
     * (say "public" instead of "public/wp") holds someone's own files, which
     * would be deleted without a word.
     */
    private function assertReplaceable(PackageInterface $package)
    {
        $path = $this->getInstallPath($package);
        if (!is_dir($path) || $this->isWordPressDir($path)) {
            return;
        }

        $entries = scandir($path);
        if ($entries === false || count(array_diff($entries, array('.', '..'))) === 0) {
            return;
        }

        throw new \InvalidArgumentException(sprintf(
            '"%s" already contains files but no WordPress install, and installing %s would delete them. '
            . 'Move them out, or point extra.%s at a new or empty directory.',
            $path,
            $package->getPrettyName(),
            self::EXTRA_KEY
        ));
    }

    /**
     * Whether a directory holds a WordPress install. wp-includes/version.php
     * marks a complete one; wp-load.php and wp-settings.php side by side mark
     * one that is damaged or partly installed, which should be replaced too.
     *
     * @param string $path
     * @return bool
     */
    private function isWordPressDir($path)
    {
        return is_file($path . '/wp-includes/version.php')
            || (is_file($path . '/wp-load.php') && is_file($path . '/wp-settings.php'));
    }

    /**
     * Composer deletes an uninstalled package's directory in the background,
     * so when one wordpress-core package replaces another in the same
     * directory, that deletion can land after the new files are in place.
     * Let any pending removal of an overlapping directory finish first.
     *
     * @param string $path
     */
    private function waitForRemovals($path)
    {
        $pending = array();
        foreach ($this->leaving as $name => $removal) {
            if ($removal[1] && $this->overlaps($path, $removal[0])) {
                $pending[] = $removal[1];
                $this->leaving[$name][1] = null;
            }
        }

        if ($pending) {
            $this->composer->getLoop()->wait($pending);
        }
    }

    /**
     * Refuse to install a package into a directory that overlaps another
     * wordpress-core package's, since removing either would delete both.
     * Replacing one package with another in the same directory is fine,
     * because Composer uninstalls the old one first.
     */
    private function claim(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        $path = $this->getInstallPath($package);
        $others = $this->claimed;
        foreach ($repo->getCanonicalPackages() as $other) {
            // Packages uninstalled earlier in this run stay in the repository until their removal finishes.
            if ($other->getType() === self::PACKAGE_TYPE && !isset($this->leaving[$other->getName()])) {
                $others[$other->getName()] = $this->getInstallPath($other);
            }
        }
        unset($others[$package->getName()]);

        foreach ($others as $name => $otherPath) {
            if ($this->overlaps($path, $otherPath)) {
                throw new \InvalidArgumentException(sprintf(
                    '%s and %s cannot share an install directory ("%s" and "%s"). Give each its own directory in extra.%s.',
                    $package->getPrettyName(),
                    $name,
                    $path,
                    $otherPath,
                    self::EXTRA_KEY
                ));
            }
        }

        $this->claimed[$package->getName()] = $path;
    }

    /**
     * Whether two normalized paths are the same directory or one contains the other.
     *
     * @param string $a
     * @param string $b
     * @return bool
     */
    private function overlaps($a, $b)
    {
        return stripos($a . '/', $b . '/') === 0 || stripos($b . '/', $a . '/') === 0;
    }

    public function getInstallPath(PackageInterface $package)
    {
        $dir = $this->configuredDir($package);
        $this->assertSafeDir($dir, $package);

        return $this->normalize($dir);
    }

    /**
     * @return string
     */
    private function configuredDir(PackageInterface $package)
    {
        $root = $this->composer->getPackage();
        $extra = $root ? $root->getExtra() : array();

        if (!isset($extra[self::EXTRA_KEY])) {
            return self::DEFAULT_DIR;
        }

        $value = $extra[self::EXTRA_KEY];
        if (is_array($value)) {
            $value = isset($value[$package->getPrettyName()]) ? $value[$package->getPrettyName()] : self::DEFAULT_DIR;
        }

        if (!is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException(sprintf(
                'extra.%s for %s must be a non-empty directory name.',
                self::EXTRA_KEY,
                $package->getPrettyName()
            ));
        }

        return $value;
    }

    /**
     * @param string $dir
     */
    private function assertSafeDir($dir, PackageInterface $package)
    {
        $path = $this->normalize($dir);
        $vendor = $this->vendorDir();
        $segments = explode('/', $path);

        if (preg_match('#^([a-zA-Z]:)?/#', str_replace('\\', '/', $dir))) {
            $reason = 'absolute paths are not allowed';
        } elseif (preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*:#', $dir)) {
            // "file:///x" and "phar://x" reach PHP's stream wrappers, and "C:wp" is relative to C:'s cwd.
            $reason = 'URLs, stream wrappers and drive letters are not allowed';
        } elseif ($path === '') {
            $reason = 'it is the project root';
        } elseif ($this->hasParentSegment($segments)) {
            $reason = 'it points outside the project';
        } elseif ($vendor !== null && strcasecmp($path, $vendor) === 0) {
            $reason = 'it is the vendor directory';
        } elseif ($vendor !== null && stripos($vendor . '/', $path . '/') === 0) {
            $reason = 'it contains the vendor directory';
        } else {
            return;
        }

        throw new \InvalidArgumentException(sprintf(
            '"%s" is not a valid WordPress install directory for %s: %s. Set extra.%s to a subdirectory such as "wp".',
            $dir,
            $package->getPrettyName(),
            $reason,
            self::EXTRA_KEY
        ));
    }

    /**
     * ".." goes up a level, and so do "..." or ".. " on Windows, which drops
     * trailing dots and spaces from each path segment.
     *
     * @param string[] $segments
     * @return bool
     */
    private function hasParentSegment(array $segments)
    {
        foreach ($segments as $segment) {
            if (trim($segment, '. ') === '') {
                return true;
            }
        }

        return false;
    }

    /**
     * The vendor directory relative to the project root, or null when it's
     * outside the project. Compared as real paths, so an absolute or
     * symlinked vendor-dir can't slip past the checks.
     *
     * @return string|null
     */
    private function vendorDir()
    {
        $root = $this->realPath(getcwd());
        $vendor = $this->realPath((string) $this->composer->getConfig()->get('vendor-dir'));

        if (stripos($vendor . '/', $root . '/') !== 0) {
            return null;
        }

        return (string) substr($vendor, strlen($root) + 1);
    }

    /**
     * realpath() for paths that may not exist yet: resolve the deepest
     * existing parent and append the rest.
     *
     * @param string $path
     * @return string
     */
    private function realPath($path)
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $rest = array();
        while ($path !== '' && ($real = realpath($path)) === false) {
            array_unshift($rest, basename($path));
            $parent = dirname($path);
            if ($parent === $path) {
                break;
            }
            $path = $parent;
        }
        if (isset($real) && $real !== false) {
            $path = $real;
        }

        return rtrim(str_replace('\\', '/', $path), '/') . ($rest ? '/' . implode('/', $rest) : '');
    }

    /**
     * Turn "./wp/", "wp\\" or "wp//" into "wp", and "." or "./" into "".
     *
     * @param string $path
     * @return string
     */
    private function normalize($path)
    {
        $parts = array();
        foreach (explode('/', str_replace('\\', '/', $path)) as $part) {
            if ($part !== '' && $part !== '.') {
                $parts[] = $part;
            }
        }

        return implode('/', $parts);
    }
}
