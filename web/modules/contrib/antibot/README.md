# Antibot

Antibot is an extremely lightweight module designed to eliminate robotic form
submissions on your website in an innovative-fashion. The module works
completely behind the scenes and doesn't require any interaction from
the end-users. The only requirement to the end user is that they must have
JavaScript enabled. If they do not, the protected forms will be hidden and a
message will appear, telling the user that the form requires JavaScript be
enabled in order to use it.

For a full description of the module, visit the
[project page](https://www.drupal.org/project/antibot).

Submit bug reports and feature suggestions, or track changes in the
[issue queue](https://www.drupal.org/project/issues/antibot).


## Table of contents

- Requirements
- Installation
- Configuration
- Troubleshooting
- Maintainers


## Requirements

This module requires no modules outside of Drupal core.


## Installation

Install as you would normally install a contributed Drupal module. For further
information, see
[Installing Drupal Modules](https://www.drupal.org/docs/extending-drupal/installing-drupal-modules).


## Configuration

1. Specify the forms you want to enable Antibot in Administration »
   User interface » Antibot.
2. Configure the user permissions in Administration » People » Permissions:
  - Administer Antibot configuration
    Users with this permission will be able to configure the Antibot settings
    in Administration » User interface » Antibot.

## Troubleshooting

If you have a custom webform that weren't working on a site with the
Antibot module enabled and protection enabled in the Webforms settings,
as a last resort include the fields:

```
{{ element.antibot_no_js }}
{{ element.antibot_key }}
```

In the form twig template and it should fine after that, for example:

```
<div class="col-md-2 offset-md-1 offset-lg-2">
    {{ element.elements.message }}
    {{ element.elements.url_redirection }}
    {{ element.form_build_id }}
    {{ element.form_token }}
    {{ element.form_id }}
    {{ element.elements.actions }}
    # Antibot keys to render.
    {{ element.antibot_no_js }}
    {% if element.antibot_key %}
        {{ element.antibot_key }}
    {% endif %}
</div>
```

It is important to add a condition to check if the key `element.antibot_key`
to make sure it wasn't added twice

## Maintainers

- Mike Stefanello - [mstef](https://www.drupal.org/u/mstef)
- Gaurav Kapoor - [gaurav.kapoor](https://www.drupal.org/u/gauravkapoor)
- Daniel Rodriguez - [danrod](https://www.drupal.org/u/danrod)
