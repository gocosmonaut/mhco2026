<?php

namespace Drupal\user_bundle;

use Drupal\user\UserStorage;

/**
 * Controller class for typed users.
 *
 * This extends the Drupal\user\UserStorage class, adding required special
 * handling for bundle-aware user objects.
 */
class TypedUserStorage extends UserStorage implements TypedUserStorageInterface {

  /**
   * {@inheritdoc}
   */
  protected function doCreate(array $values) {
    // The default user type is "user".
    if (!isset($values['type'])) {
      $values['type'] = 'user';
    }
    return parent::doCreate($values);
  }

  /**
   * {@inheritdoc}
   */
  public function updateType($old_type, $new_type) {
    // The 'type' field is stored in both the base table and the data table, so
    // update each of them, otherwise the account stays on its old type.
    $count = 0;
    foreach ($this->getTableMapping()->getAllFieldTableNames('type') as $table) {
      $count = $this->database->update($table)
        ->fields(['type' => $new_type])
        ->condition('type', $old_type)
        ->execute();
    }
    $this->resetCache();
    return $count;
  }

}
