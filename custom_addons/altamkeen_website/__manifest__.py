{
    'name': 'AL TAMKEEN Website',
    'version': '1.0.0',

    'summary': 'Corporate website customization for AL TAMKEEN',
    'category': 'Website',
    'author': 'SmartServe',
    'license': 'LGPL-3',

    'depends': [
        'website',
    ],

    'data': [
        'views/snippets.xml',
        'views/services_snippet.xml',
    ],

    'assets': {
        'web.assets_frontend': [
            'altamkeen_website/static/src/css/homepage.css',
            'altamkeen_website/static/src/js/homepage.js',
        ],
    },

    'installable': True,
    'application': False,
}