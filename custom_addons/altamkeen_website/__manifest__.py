{
    'name': 'AL TAMKEEN Website',
    'version': '1.0.0',
    'summary': 'Custom website for AL TAMKEEN',
    'author': 'SmartServe',
    'category': 'Website',
    'license': 'LGPL-3',

    'depends': [
        'website',
    ],

    'data': [
        'views/homepage.xml',
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