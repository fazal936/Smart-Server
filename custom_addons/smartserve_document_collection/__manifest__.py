{
    'name': 'SmartServe Document Collection',
    'version': '2.0.0',
    'summary': 'Internal staff workflow for secure customer document upload links',
    'category': 'Productivity',
    'author': 'SmartServe',
    'license': 'LGPL-3',
    'depends': [
        'base',
        'web',
        'mail',
        'auth_signup',
    ],
    'data': [
        'security/security.xml',
        'security/ir.model.access.csv',
        'data/user_role_data.xml',
        'data/authority_data.xml',
        'views/document_collection_views.xml',
        'views/user_management_views.xml',
        'views/upload_templates.xml',
    ],
    'assets': {
        'web.assets_backend': [
            'smartserve_document_collection/static/src/css/dashboard.css',
        ],
    },
    'installable': True,
    'application': True,
}
