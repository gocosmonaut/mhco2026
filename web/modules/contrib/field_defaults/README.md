# Field Defaults

Field Defaults allows batch updating of default field values to existing content.
When adding a new field to an entity you can update all existing content with the
default value or when editing an existing field you can choose to also update all
existing content with the new value.

For a full description of the module, visit the
[project page](https://www.drupal.org/project/field_defaults).

Submit bug reports and feature suggestions, or track changes in the
[issue queue](https://www.drupal.org/project/issues/field_defaults).


## Table of contents

- Requirements
- Installation
- Configuration
- Maintainers


## Requirements

This module requires no modules outside of Drupal core.


## Installation

Install as you would normally install a contributed Drupal module. For further
information, see
[Installing Drupal Modules](https://www.drupal.org/docs/extending-drupal/installing-drupal-modules).


## Configuration

- This module works one field at a time. You can add an additional checkbox option
  in the Default Value section in the Manage Fields > Edit page for a given field.
- On save of the field edit screen, with this option checked, all entities with this
  field attached will be updated with this field's default value, regardless of whether
  another value already exists.
- There is a settings form in Configuration >> System >> Field defaults settings
  where you can enable/disable if you want to retain the original date.


## Maintainers

- [b_sharpe](https://www.drupal.org/u/b_sharpe)
