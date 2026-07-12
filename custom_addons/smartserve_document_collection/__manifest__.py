{
    'name': 'SmartServe Document Collection',
    'version': '1.0.0',
    'summary': 'Internal staff workflow for secure customer document upload links',
    'category': 'Productivity',
    'author': 'SmartServe',
    'license': 'LGPL-3',
    'depends': [
        'base',
        'web',
    ],
    'data': [
        'security/security.xml',
        'security/ir.model.access.csv',
        'views/document_collection_views.xml',
        'views/upload_templates.xml',
    ],
    'installable': True,
    'application': True,
}
