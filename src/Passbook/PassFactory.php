<?php

/*
 * This file is part of the Passbook package.
 *
 * (c) Eymen Gunay <eymen@egunay.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Passbook;

use Exception;
use Passbook\Certificate\P12;
use Passbook\Certificate\WWDR;
use Passbook\Exception\FileException;
use Passbook\Pass\Image;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileObject;
use ZipArchive;

/**
 * PassFactory - Creates .pkpass files
 *
 * @author Eymen Gunay <eymen@egunay.com>
 */
class PassFactory
{
    /**
     * Output path for generated pass files
     *
     * @var string
     */
    protected $outputPath = '';

    /**
     * Overwrite if pass exists
     *
     * @var bool
     */
    protected $overwrite = false;

    /**
     * Pass type identifier
     *
     * @var string
     */
    protected $passTypeIdentifier;

    /**
     * Team identifier
     *
     * @var string
     */
    protected $teamIdentifier;

    /**
     * Organization name
     *
     * @var string
     */
    protected $organizationName;

    /**
     * P12 file
     *
     * @var \Passbook\Certificate\P12
     */
    protected $p12;

    /**
     * WWDR file
     *
     * @var \Passbook\Certificate\WWDR
     */
    protected $wwdr;

    /**
     * @var bool - skip signing the pass; should only be used for testing
     */
    protected $skipSignature;

    /**
     * Pass file extension
     *
     * @var string
     */
    const PASS_EXTENSION = '.pkpass';

    public function __construct($passTypeIdentifier, $teamIdentifier, $organizationName, $p12File, $p12Pass, $wwdrFile)
    {
        // Required pass information
        $this->passTypeIdentifier = $passTypeIdentifier;
        $this->teamIdentifier = $teamIdentifier;
        $this->organizationName = $organizationName;
        // Create certificate objects
        $this->p12 = new P12($p12File, $p12Pass);
        $this->wwdr = new WWDR($wwdrFile);
    }

    /**
     * Set outputPath
     *
     * @param string
     * @return $this
     */
    public function setOutputPath($outputPath)
    {
        $this->outputPath = $outputPath;

        return $this;
    }

    /**
     * Get outputPath
     *
     * @return string
     * @return $this
     */
    public function getOutputPath()
    {
        return $this->outputPath;
    }

    /**
     * Set overwrite
     *
     * @param boolean
     * @return $this
     */
    public function setOverwrite($overwrite)
    {
        $this->overwrite = $overwrite;

        return $this;
    }

    /**
     * Get overwrite
     *
     * @return boolean
     */
    public function isOverwrite()
    {
        return $this->overwrite;
    }

    /**
     * Set skip signature
     *
     * When set, the pass will not be signed when packaged. This should only
     * be used for testing.
     *
     * @param boolean
     * @return $this
     */
    public function setSkipSignature($skipSignature)
    {
        $this->skipSignature = $skipSignature;

        return $this;
    }

    /**
     * Get overwrite
     * @return boolean
     */
    public function getSkipSignature()
    {
        return $this->skipSignature;
    }

    /**
     * Serialize pass
     *
     * @param  PassInterface $pass
     *
     * @return string
     */
    public static function serialize(PassInterface $pass)
    {
        return json_encode($pass->toArray());
    }

    /**
     * Creates a pkpass file
     *
     * @param  PassInterface $pass
     *
     * @throws FileException          If an IO error occurred
     * @return SplFileObject
     */
    public function package(PassInterface $pass)
    {
        if ($pass->getSerialNumber() == '') {
            throw new \InvalidArgumentException('Pass must have a serial number to be packaged');
        }

        $this->populateRequiredInformation($pass);

        // Serialize pass
        $json = self::serialize($pass);

        $outputPath = rtrim($this->getOutputPath(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $passDir = $outputPath . $pass->getSerialNumber() . DIRECTORY_SEPARATOR;
        $passDirExists = file_exists($passDir);
        if ($passDirExists && !$this->isOverwrite()) {
            throw new FileException("Temporary pass directory already exists");
        } elseif (!$passDirExists && !mkdir($passDir, 0777, true)) {
            throw new FileException("Couldn't create temporary pass directory");
        }

        // Pass.json
        $passJSONFile = $passDir . 'pass.json';
        file_put_contents($passJSONFile, $json);

        // Images
        /** @var Image $image */
        foreach ($pass->getImages() as $image) {
            $fileName = $passDir . $image->getContext();
            if ($image->isRetina()) {
                $fileName .= '@2x';
            }
            $fileName .= '.' . $image->getExtension();
            copy($image->getPathname(), $fileName);
        }

        // Localizations
        foreach ($pass->getLocalizations() as $localization) {
            // Create dir (LANGUAGE.lproj)
            $localizationDir = $passDir . $localization->getLanguage() . '.lproj' . DIRECTORY_SEPARATOR;
            mkdir($localizationDir, 0777, true);

            // pass.strings File (Format: "token" = "value")
            $localizationStringsFile = $localizationDir . 'pass.strings';
            file_put_contents($localizationStringsFile, $localization->getStringsFileOutput());

            // Localization images
            foreach ($localization->getImages() as $image) {
                $fileName = $localizationDir . $image->getContext();
                if ($image->isRetina()) {
                    $fileName .= '@2x';
                }
                $fileName .= '.' . $image->getExtension();
                copy($image->getPathname(), $fileName);
            }
        }

        // Manifest.json - recursive, also add files in sub directories
        $manifestJSONFile = $passDir . 'manifest.json';
        $manifest = array();
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($passDir),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($files as $file) {
            // Ignore "." and ".." folders
            if (in_array(substr($file, strrpos($file, '/') + 1), array('.', '..'))) {
                continue;
            }
            //
            $filePath = realpath($file);
            if (is_file($filePath) === true) {
                $relativePathName = str_replace($passDir, '', $file->getPathname());
                $manifest[$relativePathName] = sha1_file($filePath);
            }
        }
        file_put_contents($manifestJSONFile, json_encode($manifest, JSON_UNESCAPED_SLASHES));

        // Signature
        $this->sign($passDir, $manifestJSONFile);

        // Zip pass
        $zipFile = $outputPath . $pass->getSerialNumber() . self::PASS_EXTENSION;
        $this->zip($passDir, $zipFile);

        // Remove temporary pass directory
        $this->rrmdir($passDir);

        return new SplFileObject($zipFile);
    }

    /**
     * @param $passDir
     * @param $manifestJSONFile
     */
    private function sign($passDir, $manifestJSONFile)
    {
        if ($this->getSkipSignature()) {
            return;
        }

        $signatureFile = $passDir . 'signature';
        $p12 = file_get_contents($this->p12->getRealPath());
        $certs = array();
        if (openssl_pkcs12_read($p12, $certs, $this->p12->getPassword()) == true) {
            $certdata = openssl_x509_read($certs['cert']);
            $privkey = openssl_pkey_get_private($certs['pkey'], $this->p12->getPassword());
            openssl_pkcs7_sign(
                $manifestJSONFile,
                $signatureFile,
                $certdata,
                $privkey,
                array(),
                PKCS7_BINARY | PKCS7_DETACHED,
                $this->wwdr->getRealPath()
            );
            // Get signature content
            $signature = @file_get_contents($signatureFile);
            // Check signature content
            if (!$signature) {
                throw new FileException("Couldn't read signature file.");
            }
            // Delimiters
            $begin = 'filename="smime.p7s"';
            $end = '------';
            // Convert signature
            $signature = substr($signature, strpos($signature, $begin) + strlen($begin));
            $signature = substr($signature, 0, strpos($signature, $end));
            $signature = base64_decode($signature);
            // Put new signature
            if (!file_put_contents($signatureFile, $signature)) {
                throw new FileException("Couldn't write signature file.");
            }
        } else {
            throw new FileException("Error reading certificate file");
        }
    }


    /**
     * Creates a zip of a directory including all sub directories (recursive)
     *
     * @param $source
     * @param $destination
     *
     * @return bool
     * @throws Exception
     */
    private function zip($source, $destination)
    {
        if (!extension_loaded('zip')) {
            throw new Exception("ZIP extension not available");
        }

        $zip = new ZipArchive();
        $flags = $this->isOverwrite() ? ZipArchive::OVERWRITE | ZipArchive::CREATE : ZipArchive::CREATE;
        if (!$zip->open($destination, $flags)) {
            throw new FileException("Couldn't open zip file.");
        }

        $source = str_replace('\\', '/', realpath($source));

        if (is_dir($source) === true) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source), RecursiveIteratorIterator::SELF_FIRST);

            foreach ($files as $file) {
                $file = str_replace('\\', '/', $file);

                // Ignore "." and ".." folders
                if (in_array(substr($file, strrpos($file, '/') + 1), array('.', '..'))) {
                    continue;
                }
                $file = realpath($file);

                if (is_dir($file) === true) {
                    $zip->addEmptyDir(str_replace($source . '/', '', $file . '/'));
                } else if (is_file($file) === true) {
                    $zip->addFromString(str_replace($source . '/', '', $file), file_get_contents($file));
                }
            }
        } else if (is_file($source) === true) {
            $zip->addFromString(basename($source), file_get_contents($source));
        }

        return $zip->close();
    }

    /**
     * Recursive folder remove
     * @param string $dir
     *
     * @return bool
     */
    private function rrmdir($dir)
    {
        $files = array_diff(scandir($dir), array('.', '..'));
        foreach ($files as $file) {
            is_dir("$dir/$file") ? $this->rrmdir("$dir/$file") : unlink("$dir/$file");
        }

        return rmdir($dir);
    }

    /**
     * @param PassInterface $pass
     */
    private function populateRequiredInformation(PassInterface $pass)
    {
        if (!$pass->getPassTypeIdentifier()) {
            $pass->setPassTypeIdentifier($this->passTypeIdentifier);
        }

        if (!$pass->getTeamIdentifier()) {
            $pass->setTeamIdentifier($this->teamIdentifier);
        }

        if (!$pass->getOrganizationName()) {
            $pass->setOrganizationName($this->organizationName);
        }
    }

}
