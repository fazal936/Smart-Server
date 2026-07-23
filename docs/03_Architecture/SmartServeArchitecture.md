# SmartServe Document Collection Architecture

## Customer

- Receives a secure upload link from staff
- Opens only the upload link
- Views customer name, service name, instructions, and required documents
- Uploads requested files
- Submits documents

Customers do not have accounts, logins, dashboards, profiles, or settings.

---

## Staff

- Logs into Laravel
- Creates a customer document request
- Enters customer details, requested service, required documents, due date, and internal notes
- Generates a random secure upload link
- Sends the link by email, WhatsApp, or future SMS
- Reviews uploaded documents
- Approves, rejects, or requests additional documents using the same secure link

---

## Secure Upload Link

- Random and unguessable
- Expires after a configurable period
- Can be invalidated after completion
- HTTPS-only in production
- Can allow multiple uploads when staff enables it

---

## Staff Workflow

Create Request

->

Generate Secure Link

->

Send Link to Customer

->

Customer Uploads Documents

->

Staff Reviews

->

Approve / Reject / Request More

->

Complete Request

---

## Staff Dashboard

- Requests
- Upload Requests
- Pending Documents
- Uploaded Documents
- Completed Requests
- Rejected Documents
- Internal Notes
- Activity Timeline
- Search and Filters
- Email History
- WhatsApp History

---

## Removed From Scope

- Customer Portal
- Customer Login
- Customer Dashboard
- Custo mer Document Management Dashboard
- Client Self-Service Portal
- CRM-style customer interface

---

## System

Laravel Staff Backend

->

Document Request

->

Secure Upload Request

->

Public Upload Page

->

Uploaded Attachments

->

Staff Review

---

## Modules

smartserve_document_collection

---

## Future Modules

- Email integration
- WhatsApp integration
- SMS
- AI assistant
- OCR
