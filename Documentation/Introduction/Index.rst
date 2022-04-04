.. include:: /Includes.rst.txt


.. _introduction:

Introduction
------------


.. _what-does-it-do:

What does it do?
^^^^^^^^^^^^^^^^

This Extension bundles the JumpURL functionality of TYPO3. JumpURL consists of
two components: link tracking and secure file access.


.. _introduction-link-tracking:

Link tracking
^^^^^^^^^^^^^

The redirection to external URLs will be handled by a request to TYPO3. This
allows the tracking of clicks on links to external pages. Such a URL might look
like this:

.. code-block:: none

   http://mytypo3.tld/index.php?id=1&jumpurl=http%3A%2F%2Fwww.typo3.org&juHash=XXX

When this URL is processed by TYPO3 the user will be redirected to
:samp:`http://www.typo3.org` if the sumitted juHash is valid.

The same functionality can be used for file and email links.


.. _introduction-secure-file-access:

Secure file access
^^^^^^^^^^^^^^^^^^

JumpURL can also make files downloadable that are not directly accessible by the
web server. This feature is called "JumpURL secure".

A secure JumpURL link will deliver a file if the submitted hash matches. The
record that references the file needs to be accessible by the current user. When
the referencing record is removed or hidden the file will not be delivered to
the user any more.


.. _introduction-current-state:

Current state
^^^^^^^^^^^^^

The latest version here reflects a feature-complete state.
