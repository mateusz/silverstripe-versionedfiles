<?php
/**
 * An extension that adds the ability to replace files.
 *
 * @package versionedfiles
 */
class VersionedFileExtension extends DataObjectDecorator {

	/**
	 * @return array
	 */
	public function extraStatics() {
		return array (
			'has_one'  => array('CurrentVersion' => 'FileVersion'),
			'has_many' => array('Versions'       => 'FileVersion')
		);
	}

	/**
	 * @param FieldSet $fields
	 */
	public function updateCMSFields($fields) {
		if($this->owner instanceof Folder) return;

		$fields->addFieldToTab (
			'BottomRoot.Main', new ReadonlyField('VersionNumber', 'Current Version'), 'Created'
		);

		$fields->addFieldToTab('BottomRoot.History', $versions = new TableListField (
			'Versions',
			'FileVersion',
			array (
				'VersionNumber' => 'Version Number',
				'Creator.Name'  => 'Creator',
				'Created'       => 'Date',
				'Link'          => 'Link',
				'IsCurrent'     => 'Is Current'
			),
			'"FileID" = ' . $this->owner->ID,
			'"VersionNumber" DESC'
		));

		$versions->setFieldFormatting(array (
			'Link'      => '<a href=\"$URL\">$Name</a>',
			'IsCurrent' => '{$IsCurrent()->Nice()}',
			'Created'   => '{$obj(\'Created\')->Nice()}'
		));
		$versions->disableSorting();
		$versions->setPermissions(array());

		if($this->owner->canEdit()) $fields->addFieldToTab (
			'BottomRoot.Replace', new FileField('ReplacementFile', 'Select a Replacement File')
		);
	}

	/**
	 * Creates the initial version when the file is created, or saved for the first time.
	 */
	public function onBeforeWrite() {
		if(!$this->owner->CurrentVersionID) $this->createVersion(false);
	}

	/**
	 * Since AssetAdmin does not use {@link onBeforeWrite}, onAfterUpload is also needed.
	 */
	public function onAfterUpload() {
		$this->onBeforeWrite();
	}

	/**
	 * Get the current file version number, if one is available.
	 *
	 * @return int|null
	 */
	public function getVersionNumber() {
		if($this->owner->CurrentVersionID) return $this->owner->CurrentVersion()->VersionNumber;
	}

	/**
	 * Called by the edit form upon save, and handles replacing the file if a replacement is specified.
	 *
	 * @param array $tmpFile
	 */
	public function saveReplacementFile(array $tmpFile) {
		if($tmpFile['error'] !=  UPLOAD_ERR_OK) return;

		$upload  = new Upload();
		$tmpFile = array_merge($tmpFile, array('name' => $this->owner->Name));
		$folder  = null;

		if($this->owner->ParentID) {
			$folder = substr($this->owner->Parent()->getRelativePath(), strlen(ASSETS_DIR) + 1, -1);
		}

		if(!$upload->validate($tmpFile)) {
			throw new Exception (
				"Could not replace file $file->ID: " . implode(', ', $upload->getErrors())
			);
		}

		// the file must be removed to prevent the upload being renamed
		unlink($this->owner->getFullPath());
		$upload->loadIntoFile($tmpFile, $this->owner, $folder);

		$this->createVersion();
	}

	/**
	 * Creates a new file version and sets it as the current version.
	 *
	 * @param bool $write
	 */
	public function createVersion($write = true) {
		$version = new FileVersion();
		$version->FileID = $this->owner->ID;
		$version->write();

		$this->owner->CurrentVersionID = $version->ID;
		if($write) $this->owner->write();
	}

}