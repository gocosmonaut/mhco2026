<?php

namespace Drupal\mhco_form_filler\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * Provides an 'Admin Form Generation' block.
 *
 * @Block(
 * id = "admin_form_generation_block",
 * admin_label = @Translation("MHCO Admin Form Generation Selector"),
 * )
 */
class AdminFormGenerationBlock extends BlockBase
{
    public function build()
    {
      // Return the custom form we created above
        return \Drupal::formBuilder()->getForm('\Drupal\mhco_form_filler\Form\AdminFormGenerationForm');
    }
}
