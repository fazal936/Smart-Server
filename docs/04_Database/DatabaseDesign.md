# SmartServe Platform Database Design

## Service Category

- Business Setup
- PRO Services
- UAE Visa Services
- International Visa Services
- Travel & Tourism
- Legal Services
- HR Consultancy
- Insurance
- Driving Licence Services
- Document Attestation
- Real Estate
- Corporate Advisory

---

## Service

Fields

- Service Name
- Category
- Description
- Required Documents
- Estimated Processing Time
- Status (Active/Inactive)

---

## Application

Fields

- Reference Number
- Customer Name
- Mobile
- Email
- Service
- Status
- Assigned Employee
- Date Created

---

## Documents

Fields

- Application
- Document Type
- File
- Uploaded By
- Upload Date

---

## Workflow

New

↓

Documents Pending

↓

Documents Received

↓

Processing

↓

Government Processing

↓

Completed