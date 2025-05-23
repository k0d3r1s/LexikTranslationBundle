<?php

namespace Lexik\Bundle\TranslationBundle\Manager;

use Lexik\Bundle\TranslationBundle\Model\Translation;
use Lexik\Bundle\TranslationBundle\Storage\StorageInterface;
use Lexik\Bundle\TranslationBundle\Storage\PropelStorage;

/**
 * Class to manage TransUnit entities or documents.
 *
 * @author Cédric Girard <c.girard@lexik.fr>
 */
class TransUnitManager implements TransUnitManagerInterface
{
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly FileManagerInterface $fileManager,
        private readonly string $kernelRootDir,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function newInstance($locales = [])
    {
        $transUnitClass = $this->storage->getModelClass('trans_unit');
        $translationClass = $this->storage->getModelClass('translation');

        $transUnit = new $transUnitClass();

        foreach ($locales as $locale) {
            $translation = new $translationClass();
            $translation->setLocale($locale);

            $transUnit->addTranslation($translation);
        }

        return $transUnit;
    }

    /**
     * {@inheritdoc}
     */
    public function create($keyName, $domainName, $flush = false)
    {
        $transUnit = $this->newInstance();
        $transUnit->setKey($keyName);
        $transUnit->setDomain($domainName);

        $this->storage->persist($transUnit);

        if ($flush) {
            $this->storage->flush();
        }

        return $transUnit;
    }

    /**
     * {@inheritdoc}
     */
    public function addTranslation(
        TransUnitInterface $transUnit,
        $locale,
        $content,
        FileInterface $file = null,
        $flush = false
    ) {
        $translation = null;

        if (!$transUnit->hasTranslation($locale)) {
            $class = $this->storage->getModelClass('translation');

            $translation = new $class();
            $translation->setLocale($locale);
            $translation->setContent($content);

            if ($file !== null) {
                $translation->setFile($file);
            }

            $transUnit->addTranslation($translation);

            $this->storage->persist($translation);

            if ($flush) {
                $this->storage->flush();
            }
        }

        return $translation;
    }

    /**
     * {@inheritdoc}
     */
    public function updateTranslation(TransUnitInterface $transUnit, $locale, $content, $flush = false, $merge = false)
    {
        $translation = null;
        $i = 0;
        $end = $transUnit->getTranslations()->count();
        $found = false;

        while ($i < $end && !$found) {
            $found = ($transUnit->getTranslations()->get($i)->getLocale() == $locale);
            $i++;
        }

        if ($found) {
            /* @var Translation $translation */
            $translation = $transUnit->getTranslations()->get($i - 1);
            if ($merge) {
                if ($translation->isModifiedManually() || $translation->getContent() == $content) {
                    return null;
                }

                $newTranslation = clone $translation;
                $this->storage->remove($translation);
                $this->storage->flush();

                $newTranslation->setContent($content);
                $this->storage->persist($newTranslation);
                $translation = $newTranslation;
            }

            $translation->setContent($content);
        }

        if (null !== $translation && $this->storage instanceof PropelStorage) {
            $this->storage->persist($translation);
        }

        if ($flush) {
            $this->storage->flush();
        }

        return $translation;
    }

    /**
     * {@inheritdoc}
     */
    public function updateTranslationsContent(TransUnitInterface $transUnit, array $translations, $flush = false)
    {
        foreach ($translations as $locale => $content) {
            if (!empty($content)) {
                /** @var TranslationInterface|null $translation */
                $translation = $transUnit->getTranslation($locale);
                $contentUpdated = true;

                if ($translation instanceof TranslationInterface) {
                    $originalContent = $translation->getContent();
                    $translation = $this->updateTranslation($transUnit, $locale, $content);

                    $contentUpdated = ($translation->getContent() != $originalContent);

                    if ($this->storage instanceof PropelStorage) {
                        $this->storage->persist($transUnit);
                    }
                } else {
                    //We need to get a proper file for this translation
                    $file = $this->getTranslationFile($transUnit, $locale);
                    $translation = $this->addTranslation($transUnit, $locale, $content, $file);
                }

                if ($translation instanceof Translation && $contentUpdated) {
                    $translation->setModifiedManually(true);
                }
            }
        }

        if ($flush) {
            $this->storage->flush();
        }
    }

    /**
     * Get the proper File for this TransUnit and locale
     */
    public function getTranslationFile(TransUnitInterface &$transUnit, string $locale)
    {
        $file = null;
        foreach ($transUnit->getTranslations() as $translation) {
            if (null !== $file = $translation->getFile()) {
                break;
            }
        }

        //if we found a file
        if ($file !== null) {
            //make sure we got the correct file for this locale and domain
            $name = sprintf('%s.%s.%s', $file->getDomain(), $locale, $file->getExtention());
            $file = $this->fileManager->getFor($name, $this->kernelRootDir . DIRECTORY_SEPARATOR . $file->getPath());
        }

        return $file;
    }

    /**
     * @return bool
     */
    public function delete(TransUnitInterface $transUnit)
    {
        try {
            $this->storage->remove($transUnit);
            $this->storage->flush();

            return true;

        } catch (\Exception) {
            return false;
        }
    }

    /**
     * @param string $locale
     * @return bool
     */
    public function deleteTranslation(TransUnitInterface $transUnit, $locale)
    {
        try {
            $translation = $transUnit->getTranslation($locale);

            $this->storage->remove($translation);
            $this->storage->flush();

            return true;

        } catch (\Exception) {
            return false;
        }
    }
}
