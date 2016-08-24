<?php

namespace OC;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\IImage;
use OCP\Image;
use OCP\IPreview;
use OCP\Preview\IProvider;

class Preview2 {
	//the thumbnail folder
	const THUMBNAILS_FOLDER = 'thumbnails';

	const MODE_FILL = 'fill';
	const MODE_COVER = 'cover';

	/** @var IRootFolder*/
	private $rootFolder;
	/** @var File */
	private $file;
	/** @var IPreview */
	private $previewManager;
	/** @var IConfig */
	private $config;

	public function __construct(
		IRootFolder $rootFolder,
		IConfig $config,
		IPreview $previewManager,
		File $file
	) {
		$this->rootFolder = $rootFolder;
		$this->config = $config;
		$this->file = $file;
		$this->previewManager = $previewManager;
	}

	/**
	 * Returns a preview of a file
	 *
	 * The cache is searched first and if nothing usable was found then a preview is
	 * generated by one of the providers
	 *
	 * @param int $width
	 * @param int $height
	 * @param bool $crop
	 * @param string $mode
	 * @return File
	 * @throws NotFoundException
	 */
	public function getPreview($width = -1, $height = -1, $crop = false, $mode = Preview2::MODE_FILL) {
		if (!$this->previewManager->isMimeSupported($this->file->getMimeType())) {
			throw new NotFoundException();
		}

		/*
		 * Get the preview folder
		 * TODO: Separate preview creation from storing previews
		 */
		$previewFolder = $this->getPreviewFolder();

		// Get the max preview and infer the max preview sizes from that
		$maxPreview = $this->getMaxPreview($previewFolder);
		list($maxWidth, $maxHeight) = $this->getPreviewSize($maxPreview);

		// Calculate the preview size
		list($width, $height) = $this->calculateSize($width, $height, $crop, $mode, $maxWidth, $maxHeight);

		// Try to get a cached preview. Else generate (and store) one
		try {
			$file = $this->getCachedPreview($previewFolder, $width, $height, $crop);
		} catch (NotFoundException $e) {
			$file = $this->generatePreview($previewFolder, $maxPreview, $width, $height, $crop, $maxWidth, $maxHeight);
		}

		return $file;
	}

	/**
	 * @param Folder $previewFolder
	 * @return File
	 * @throws NotFoundException
	 */
	private function getMaxPreview(Folder $previewFolder) {
		$nodes = $previewFolder->getDirectoryListing();

		/** @var File $node */
		foreach ($nodes as $node) {
			if (strpos($node->getName(), 'max')) {
				return $node;
			}
		}

		$previewProviders = $this->previewManager->getProviders();
		foreach ($previewProviders as $supportedMimeType => $providers) {
			if (!preg_match($supportedMimeType, $this->file->getMimeType())) {
				continue;
			}

			foreach ($providers as $provider) {
				$provider = $provider();
				if (!($provider instanceof IProvider)) {
					continue;
				}

				list($view, $path) = $this->getViewAndPath($this->file);

				$maxWidth = (int)$this->config->getSystemValue('preview_max_x', 2048);
				$maxHeight = (int)$this->config->getSystemValue('preview_max_y', 2048);

				$preview = $provider->getThumbnail($path, $maxWidth, $maxHeight, false, $view);

				if (!($preview instanceof IImage)) {
					continue;
				}

				$path = strval($preview->width()) . '-' . strval($preview->height()) . '-max.png';
				$file = $previewFolder->newFile($path);
				$file->putContent($preview->data());

				return $file;
			}
		}

		throw new NotFoundException();
	}

	/**
	 * @param File $file
	 * @return int[]
	 */
	private function getPreviewSize(File $file) {
		$size = explode('-', $file->getName());
		return [(int)$size[0], (int)$size[1]];
	}

	/**
	 * @param int $width
	 * @param int $height
	 * @param bool $crop
	 * @return string
	 */
	private function generatePath($width, $height, $crop) {
		$path = strval($width) . '-' . strval($height);
		if ($crop) {
			$path .= '-crop';
		}
		$path .= '.png';
		return $path;
	}

	/**
	 * @param File $file
	 * @return array
	 * This is required to create the old view and path
	 */
	private function getViewAndPath(File $file) {
		$owner = $file->getOwner()->getUID();

		$userFolder = $this->rootFolder->getUserFolder($owner)->getParent();
		$nodes = $userFolder->getById($file->getId());

		$file = $nodes[0];

		$view = new \OC\Files\View($userFolder->getPath());
		$path = $userFolder->getRelativePath($file->getPath());

		return [$view, $path];
	}

	/**
	 * @param int $width
	 * @param int $height
	 * @param bool $crop
	 * @param string $mode
	 * @param int $maxWidth
	 * @param int $maxHeight
	 * @return int[]
	 */
	private function calculateSize($width, $height, $crop, $mode, $maxWidth, $maxHeight) {

		/*
		 * If we are not cropping we have to make sure the requested image
		 * respects the asepct ratio of the original.
		 */
		if (!$crop) {
			$ratio = $maxHeight / $maxWidth;

			if ($width === -1) {
				$width = $height / $ratio;
			}
			if ($height === -1) {
				$height = $width * $ratio;
			}

			$ratioH = $height / $maxHeight;
			$ratioW = $width / $maxWidth;

			/*
			 * Fill means that the $height and $width are the max
			 * Cover means min.
			 */
			if ($mode === self::MODE_FILL) {
				if ($ratioH > $ratioW) {
					$height = $width * $ratio;
				} else {
					$width = $height / $ratio;
				}
			} else if ($mode === self::MODE_COVER) {
				if ($ratioH > $ratioW) {
					$width = $height / $ratio;
				} else {
					$height = $width * $ratio;
				}
			}
		}

		if ($height !== $maxHeight && $width !== $maxWidth) {
			/*
			 * Scale to the nearest power of two
			 */
			$pow2heigth = pow(2, ceil(log($height) / log(2)));
			$pow2width = pow(2, ceil(log($width) / log(2)));

			$ratioH = $height / $pow2heigth;
			$ratioW = $width / $pow2width;

			if ($ratioH < $ratioW) {
				$width = $pow2width;
				$height = $height / $ratioW;
			} else {
				$height = $pow2heigth;
				$width = $width / $ratioH;
			}
		}

		/*
 		 * Make sure the requested height and width fall within the max
 		 * of the preview.
 		 */
		if ($height > $maxHeight) {
			$ratio = $height / $maxHeight;
			$height = $maxHeight;
			$width = $width / $ratio;
		}
		if ($width > $maxWidth) {
			$ratio = $width / $maxWidth;
			$width = $maxWidth;
			$height = $height / $ratio;
		}

		return [(int)round($width), (int)round($height)];
	}

	/**
	 * @param Folder $previewFolder
	 * @param File $maxPreview
	 * @param int $width
	 * @param int $height
	 * @param bool $crop
	 * @param int $maxWidth,
	 * @param int $maxHeight
	 * @return File
	 * @throws NotFoundException
	 */
	private function generatePreview(Folder $previewFolder, File $maxPreview, $width, $height, $crop, $maxWidth, $maxHeight) {
		$preview = new Image($maxPreview->getContent());

		if ($crop) {
			if ($height !== $preview->height() && $width !== $preview->width()) {
				//Resize
				$widthR = $preview->width() / $width;
				$heightR = $preview->height() / $height;

				if ($widthR > $heightR) {
					$scaleH = $height;
					$scaleW = $maxWidth / $heightR;
				} else {
					$scaleH = $maxHeight / $widthR;
					$scaleW = $width;
				}
				$preview->preciseResize(round($scaleW), round($scaleH));
			}
			$cropX = floor(abs($width - $preview->width()) * 0.5);
			$cropY = 0;
			$preview->crop($cropX, $cropY, $width, $height);
		} else {
			$preview->resize(max($width, $height));
		}

		$path = $this->generatePath($width, $height, $crop);
		$file = $previewFolder->newFile($path);
		$file->putContent($preview->data());

		return $file;
	}

	/**
	 * @param Folder $previewFolder
	 * @param int $width
	 * @param int $height
	 * @param bool $crop
	 * @return File
	 *
	 * @throws NotFoundException
	 */
	private function getCachedPreview(Folder $previewFolder, $width, $height, $crop) {
		$path = $this->generatePath($width, $height, $crop);

		return $previewFolder->get($path);
	}

	/**
	 * Get the specific preview folder for this file
	 *
	 * @return Folder
	 */
	private function getPreviewFolder() {
		$user = $this->file->getOwner();
		$user = $user->getUID();

		$previewRoot = $this->rootFolder->getUserFolder($user);
		$previewRoot = $previewRoot->getParent();

		try {
			$previewRoot = $previewRoot->get(self::THUMBNAILS_FOLDER);
		} catch (NotFoundException $e) {
			$previewRoot = $previewRoot->newFolder(self::THUMBNAILS_FOLDER);
		}

		try {
			$previewFolder = $previewRoot->get($this->file->getId());
		} catch (NotFoundException $e) {
			$previewFolder = $previewRoot->newFolder($this->file->getId());
		}

		return $previewFolder;
	}
}
